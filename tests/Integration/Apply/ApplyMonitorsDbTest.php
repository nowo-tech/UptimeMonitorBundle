<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Apply;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Driver\AbstractSQLiteDriver\Middleware\EnableForeignKeys;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Apply\ApplyError;
use Nowo\UptimeMonitorBundle\Apply\ApplyFailedException;
use Nowo\UptimeMonitorBundle\Apply\ApplyRefusedException;
use Nowo\UptimeMonitorBundle\Apply\ApplyResult;
use Nowo\UptimeMonitorBundle\Apply\ExternalPushTokens;
use Nowo\UptimeMonitorBundle\Apply\MonitorApplier;
use Nowo\UptimeMonitorBundle\Apply\MonitorFileParser;
use Nowo\UptimeMonitorBundle\Apply\MonitorFileSpec;
use Nowo\UptimeMonitorBundle\Apply\PushTokenFileReader;
use Nowo\UptimeMonitorBundle\Apply\TokenSourceSystem;
use Nowo\UptimeMonitorBundle\Command\ApplyMonitorsCommand;
use Nowo\UptimeMonitorBundle\Controller\PushController;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorsAppliedEvent;
use Nowo\UptimeMonitorBundle\Limits\ConfigurableTenantLimits;
use Nowo\UptimeMonitorBundle\Limits\TenantQuota;
use Nowo\UptimeMonitorBundle\Push\PushCredentials;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Tests\Support\RecordingLogger;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use Nowo\UptimeMonitorBundle\Tests\Support\StatementLog;
use Nowo\UptimeMonitorBundle\Tests\Unit\Apply\MonitorFileParserTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function array_column;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function bin2hex;
use function chmod;
use function count;
use function file_put_contents;
use function fopen;
use function fwrite;
use function getenv;
use function hash;
use function implode;
use function is_string;
use function json_decode;
use function json_encode;
use function random_bytes;
use function rewind;
use function serialize;
use function str_repeat;
use function strtoupper;
use function substr;
use function symlink;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function var_export;

use const JSON_THROW_ON_ERROR;

#[Group('db')]
#[CoversClass(MonitorApplier::class)]
#[CoversClass(ApplyMonitorsCommand::class)]
final class ApplyMonitorsDbTest extends TestCase
{
    private const TENANT  = MonitorFileParserTest::TENANT;
    private const OTHER   = MonitorFileParserTest::OTHER;
    private const URL_KEY = '0123456789abcdef0123456789abcdef';

    private EntityManagerInterface $em;
    private MonitorRepository $monitors;
    private TenantRepository $tenants;
    private Tenant $tenant;
    private RecordingLogger $logger;
    private StatementLog $statements;
    /** @var list<object> */
    private array $events = [];
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->statements = new StatementLog();
        // SQLite enforces foreign keys (the prune cascade) only when asked; the real databases always do.
        $sqlite   = !is_string(getenv(SqliteEntityManagerFactory::DATABASE_URL_ENV)) || getenv(SqliteEntityManagerFactory::DATABASE_URL_ENV) === '';
        $this->em = SqliteEntityManagerFactory::create((new DbalConfiguration())->setMiddlewares($sqlite ? [$this->statements, new EnableForeignKeys()] : [$this->statements]));
        $this->wire($this->em);
        $this->tenant = new Tenant(self::TENANT, 'Infra');
        $this->em->persist($this->tenant);
        $this->em->persist(new Tenant(self::OTHER, 'Other'));
        $this->em->flush();
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function wire(EntityManagerInterface $em): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $this->monitors = new MonitorRepository($registry);
        $this->tenants  = new TenantRepository($registry);
    }

    private function applier(?TenantQuota $quota = null, ?EntityManagerInterface $em = null): MonitorApplier
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MonitorsAppliedEvent::class, function (MonitorsAppliedEvent $event): void {
            $this->events[] = $event;
        });

        return new MonitorApplier($em ?? $this->em, $this->monitors, $this->tenants, $quota, $dispatcher);
    }

    private function tester(?MonitorApplier $applier = null): CommandTester
    {
        return new CommandTester(new ApplyMonitorsCommand(new MonitorFileParser(), new PushTokenFileReader(), $applier ?? $this->applier(), $this->logger));
    }

    private function path(string $content, int $mode = 0o600): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'uptime-apply');
        file_put_contents($path, $content);
        chmod($path, $mode);
        $this->files[] = $path;

        return $path;
    }

    /**
     * @param list<array<string, mixed>> $monitors
     */
    private function monitorsFile(array $monitors, string $tenant = self::TENANT): string
    {
        return $this->path(MonitorFileParserTest::file($monitors, $tenant), 0o644);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{0: int, 1: array<string, mixed>, 2: string}
     */
    private function runCommand(array $options, string $stdin = '', ?MonitorApplier $applier = null): array
    {
        $tester = $this->tester($applier);
        $tester->setInputs([$stdin]);
        $code = $tester->execute($options, ['capture_stderr_separately' => true]);
        /** @var array<string, mixed> $json */
        $json = json_decode($tester->getDisplay(), true, 16, JSON_THROW_ON_ERROR);

        return [$code, $json, $tester->getErrorOutput()];
    }

    private function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function fileMonitor(string $key = 'little-vps-1.resources'): ?Monitor
    {
        $this->em->clear();

        return $this->monitors->findOneBy(['originKey' => $key]);
    }

    public function testCreateThenUnchangedThenUpdateAndRotate(): void
    {
        $token = $this->token();
        $file  = $this->monitorsFile([MonitorFileParserTest::entry(['grace_seconds' => 120, 'description' => 'Resources', 'project' => 'infra', 'paused' => false])]);

        [$code, $json, $stderr] = $this->runCommand(['--file' => $file, '--token-file' => '-'], json_encode(['little-vps-1.resources' => $token], JSON_THROW_ON_ERROR));
        self::assertSame(0, $code);
        self::assertTrue($json['ok']);
        self::assertSame('set', $json['created'][0]['token']);
        self::assertSame([['tenant' => self::TENANT, 'tenant_enabled' => true]], $json['tenants']);
        self::assertStringContainsString('Applied: 1 created', $stderr);
        $monitor = $this->fileMonitor();
        self::assertInstanceOf(Monitor::class, $monitor);
        self::assertSame([MonitorType::Push, 'push', self::URL_KEY, Monitor::ORIGIN_FILE, 60, 'infra'], [$monitor->getType(), $monitor->getTarget(), $monitor->getPushKey(), $monitor->getOrigin(), $monitor->getIntervalSeconds(), $monitor->getProject()]);
        self::assertSame(hash('sha256', $token), $monitor->getPushTokenHash());
        self::assertTrue((new PushCredentials('secret'))->verifyToken($monitor, $token));
        self::assertSame(120, $monitor->getConfig()['push_grace_seconds']);
        self::assertSame('Resources', $monitor->getConfig()['description']);
        self::assertFalse($monitor->getConfig()['push_signed']);
        self::assertSame(60, $monitor->getRetryIntervalSeconds());
        self::assertCount(1, $this->events);

        // Same file, same token: nothing written.
        [$code, $json] = $this->runCommand(['--file' => $file, '--token-file' => '-'], json_encode(['little-vps-1.resources' => $token], JSON_THROW_ON_ERROR));
        self::assertSame(0, $code);
        self::assertSame([['tenant' => self::TENANT, 'key' => 'little-vps-1.resources', 'id' => $monitor->getId(), 'token' => 'unchanged']], $json['unchanged']);

        // No token input: kept.
        [, $json] = $this->runCommand(['--file' => $file]);
        self::assertSame('kept', $json['unchanged'][0]['token']);

        // A field and the token change: updated, the old token stops.
        $monitor->setConfig($monitor->getConfig() + ['failure_streak' => 2]);
        $this->em->flush();
        $rotated       = $this->token();
        $file2         = $this->monitorsFile([MonitorFileParserTest::entry(['interval_seconds' => 120, 'thresholds' => ['disk' => ['warn' => 70, 'critical' => 80]]])]);
        [$code, $json] = $this->runCommand(['--file' => $file2, '--token-file' => '-'], json_encode(['little-vps-1.resources' => $rotated], JSON_THROW_ON_ERROR));
        self::assertSame(0, $code);
        self::assertSame(['project', 'description', 'interval_seconds', 'grace_seconds', 'thresholds.disk', 'token'], $json['updated'][0]['changed']);
        self::assertSame('rotated', $json['updated'][0]['token']);
        $monitor = $this->fileMonitor();
        self::assertInstanceOf(Monitor::class, $monitor);
        self::assertFalse((new PushCredentials('secret'))->verifyToken($monitor, $token));
        self::assertTrue((new PushCredentials('secret'))->verifyToken($monitor, $rotated));
        self::assertNull($monitor->getProject());
        self::assertArrayNotHasKey('push_grace_seconds', $monitor->getConfig());
        self::assertSame(2, $monitor->getConfig()['failure_streak'], 'internal counters survive');
        self::assertSame([70.0, 80.0], $monitor->getConfig()['push_thresholds']['disk']);
    }

    public function testPausedIsManagedOnlyWhenTheFileSaysSo(): void
    {
        $this->runCommand(['--file' => $this->monitorsFile([MonitorFileParserTest::entry()])]);
        $monitor = $this->fileMonitor();
        self::assertInstanceOf(Monitor::class, $monitor);
        self::assertFalse($monitor->isPaused());
        $monitor->setPaused(true);
        $this->em->flush();

        [, $json] = $this->runCommand(['--file' => $this->monitorsFile([MonitorFileParserTest::entry()])]);
        self::assertCount(1, $json['unchanged'], 'an ops mute survives a redeploy');
        self::assertTrue($this->fileMonitor()?->isPaused());

        [, $json] = $this->runCommand(['--file' => $this->monitorsFile([MonitorFileParserTest::entry(['paused' => false])])]);
        self::assertSame(['paused'], $json['updated'][0]['changed']);
        self::assertFalse($this->fileMonitor()?->isPaused());
    }

    public function testDryRunWritesNothingAndPlansTheSame(): void
    {
        $file                   = $this->monitorsFile([MonitorFileParserTest::entry()]);
        [$code, $json, $stderr] = $this->runCommand(['--file' => $file, '--dry-run' => true], '', $this->applier(new TenantQuota(new ConfigurableTenantLimits(5), $this->monitors)));
        self::assertSame(0, $code);
        self::assertTrue($json['dry_run']);
        self::assertTrue($json['quota_checked_unlocked']);
        self::assertNull($json['created'][0]['id']);
        self::assertSame('absent', $json['created'][0]['token']);
        self::assertStringContainsString('Dry run', $stderr);
        self::assertNull($this->fileMonitor());
        self::assertSame([], $this->events);
    }

    public function testUiMonitorsAreNeverTouchedAndPruneIsExplicit(): void
    {
        $ui = new Monitor($this->tenant, 'little-vps-1.resources', MonitorType::Https, 'https://example.test');
        $this->em->persist($ui);
        $this->em->flush();
        $this->runCommand(['--file' => $this->monitorsFile([MonitorFileParserTest::entry(), MonitorFileParserTest::entry(['key' => 'gone', 'url_key' => 'fedcba9876543210fedcba9876543210'])])]);
        $goneId = $this->fileMonitor('gone')?->getId();

        [, $json] = $this->runCommand(['--file' => $this->monitorsFile([MonitorFileParserTest::entry()])]);
        self::assertSame([['tenant' => self::TENANT, 'key' => 'gone', 'id' => $goneId]], $json['prune_candidates']);
        self::assertSame([], $json['pruned']);
        self::assertNotNull($this->fileMonitor('gone'));

        [, $json] = $this->runCommand(['--file' => $this->monitorsFile([MonitorFileParserTest::entry()]), '--prune' => true]);
        self::assertSame([['tenant' => self::TENANT, 'key' => 'gone', 'id' => $goneId]], $json['pruned']);
        self::assertNull($this->fileMonitor('gone'));

        // An empty tenant block prunes every file monitor of that tenant, and only of that tenant.
        $empty    = $this->path('{"version": 1, "tenants": {"' . self::TENANT . '": {"monitors": []}}}', 0o644);
        [, $json] = $this->runCommand(['--file' => $empty, '--prune' => true]);
        self::assertCount(1, $json['pruned']);
        $this->em->clear();
        self::assertSame(['little-vps-1.resources'], array_column(array_map(static fn (Monitor $m): array => ['n' => $m->getName()], $this->monitors->findAll()), 'n'));
        self::assertSame(Monitor::ORIGIN_UI, $this->monitors->findAll()[0]->getOrigin());
    }

    public function testTenantScopeAndUnknownTenants(): void
    {
        $file = $this->path(json_encode(['version' => 1, 'tenants' => [
            self::TENANT => ['monitors' => [MonitorFileParserTest::entry()]],
            self::OTHER  => ['monitors' => [MonitorFileParserTest::entry(['key' => 'other', 'url_key' => 'fedcba9876543210fedcba9876543210'])]],
        ]], JSON_THROW_ON_ERROR), 0o644);

        [$code, $json] = $this->runCommand(['--file' => $file, '--tenant' => self::OTHER]);
        self::assertSame(0, $code);
        self::assertSame(['other'], array_column($json['created'], 'key'));
        self::assertNull($this->fileMonitor());

        [$code, $json] = $this->runCommand(['--file' => $file, '--tenant' => '00000000-0000-4000-8000-00000000000f']);
        self::assertSame([2, [['path' => '--tenant', 'code' => 'tenant_not_in_file']]], [$code, $json['errors']]);

        [$code, $json] = $this->runCommand(['--file' => $this->monitorsFile([MonitorFileParserTest::entry()], '00000000-0000-4000-8000-00000000000f')]);
        self::assertSame([5, 'tenant_not_found'], [$code, $json['errors'][0]['code']]);
        self::assertFalse($json['ok']);
        self::assertNull($this->fileMonitor());
    }

    public function testUsageErrors(): void
    {
        [$code, $json] = $this->runCommand(['--tenant' => 'Main', '--format' => 'text', '--token-file' => '']);
        self::assertSame(2, $code);
        self::assertSame(['--file', '--token-file', '--tenant', '--format'], array_column($json['errors'], 'path'));
        self::assertNull($json['file_sha256']);

        [$code, $json] = $this->runCommand(['--file' => '/nonexistent/monitors.json']);
        self::assertSame([3, 'file_not_found'], [$code, $json['errors'][0]['code']]);
    }

    public function testConflictsRefuseTheWholeFile(): void
    {
        $holder = (new Monitor($this->tenant, 'Hand made', MonitorType::Push, 'push'))->setPushKey(self::URL_KEY)->setPushTokenHash(hash('sha256', 'x'));
        $this->em->persist($holder);
        $this->em->flush();
        $reused = $this->token();
        $holder->setPushTokenHash(hash('sha256', $reused));
        $this->em->flush();

        $file = $this->monitorsFile([
            MonitorFileParserTest::entry(['key' => 'fresh', 'url_key' => 'fedcba9876543210fedcba9876543210']),
            MonitorFileParserTest::entry(),
        ]);
        [$code, $json] = $this->runCommand(['--file' => $file, '--token-file' => '-'], json_encode(['fresh' => $reused], JSON_THROW_ON_ERROR), $this->applier(new TenantQuota(new ConfigurableTenantLimits(0, 120), $this->monitors)));

        self::assertSame(4, $code);
        self::assertSame(
            ['below_tenant_minimum', 'token_reused', 'below_tenant_minimum', 'url_key_taken'],
            array_column($json['errors'], 'code'),
        );
        self::assertNull($this->fileMonitor('fresh'), 'nothing applied');
    }

    public function testQuotaCountsCreatesAgainstPrunes(): void
    {
        $quota         = new TenantQuota(new ConfigurableTenantLimits(1), $this->monitors);
        $two           = $this->monitorsFile([MonitorFileParserTest::entry(), MonitorFileParserTest::entry(['key' => 'b', 'url_key' => 'fedcba9876543210fedcba9876543210'])]);
        [$code, $json] = $this->runCommand(['--file' => $two], '', $this->applier($quota));
        self::assertSame([6, [['path' => '/tenants/' . self::TENANT, 'code' => 'over_quota']]], [$code, $json['errors']]);

        $this->runCommand(['--file' => $this->monitorsFile([MonitorFileParserTest::entry()])], '', $this->applier($quota));
        $replace = $this->monitorsFile([MonitorFileParserTest::entry(['key' => 'b', 'url_key' => 'fedcba9876543210fedcba9876543210'])]);
        [$code]  = $this->runCommand(['--file' => $replace], '', $this->applier($quota));
        self::assertSame(6, $code, 'without --prune the old one still counts');
        [$code, $json] = $this->runCommand(['--file' => $replace, '--prune' => true], '', $this->applier($quota));
        self::assertSame(0, $code);
        self::assertSame(['b'], array_column($json['created'], 'key'));
    }

    public function testAFileMonitorThatIsNoLongerPushIsRefused(): void
    {
        $this->runCommand(['--file' => $this->monitorsFile([MonitorFileParserTest::entry()])]);
        $this->em->getConnection()->executeStatement("UPDATE uptime_monitor SET type = 'https', push_key = NULL");
        $this->em->clear();

        [$code, $json] = $this->runCommand(['--file' => $this->monitorsFile([MonitorFileParserTest::entry()])]);
        self::assertSame([6, 'type_change_refused'], [$code, $json['errors'][0]['code']]);
    }

    /**
     * R2: neither the token nor its hash reaches stdout, stderr, logs, events or any exception (message, previous chain,
     * trace) on any path, including a database failure on the hash column.
     */
    public function testNoTokenMaterialLeaksOnAnyPath(): void
    {
        $token = $this->token();
        $hash  = hash('sha256', $token);
        $map   = json_encode(['little-vps-1.resources' => $token], JSON_THROW_ON_ERROR);
        $good  = $this->monitorsFile([MonitorFileParserTest::entry()]);
        $bad   = $this->monitorsFile([MonitorFileParserTest::entry(['interval_seconds' => 5])]);
        $leaks = static function (string $text) use ($token, $hash): void {
            self::assertStringNotContainsString($token, $text);
            self::assertStringNotContainsString($hash, $text);
        };
        $paths = [
            'dry run'          => [['--file' => $good, '--token-file' => '-', '--dry-run' => true], $map, null],
            'invalid file'     => [['--file' => $bad, '--token-file' => '-'], $map, null],
            'weak token'       => [['--file' => $good, '--token-file' => '-'], json_encode(['little-vps-1.resources' => substr($token, 0, 62)], JSON_THROW_ON_ERROR), null],
            'open token file'  => [['--file' => $good, '--token-file' => $this->path($map, 0o644)], '', null],
            'inverted map'     => [['--file' => $good, '--token-file' => '-'], json_encode([$token => 'little-vps-1.resources'], JSON_THROW_ON_ERROR), null],
            'inverted, bad'    => [['--file' => $bad, '--token-file' => '-'], json_encode([$token => 'little-vps-1.resources'], JSON_THROW_ON_ERROR), null],
            'success'          => [['--file' => $good, '--token-file' => '-'], $map, null],
            'database failure' => [['--file' => $good, '--token-file' => '-'], $map, $this->isolatedApplier('failing', $hash, $token)],
        ];
        foreach ($paths as $name => [$options, $stdin, $applier]) {
            $tester = $this->tester($applier);
            $tester->setInputs([$stdin]);
            $tester->execute($options, ['capture_stderr_separately' => true, 'verbosity' => OutputInterface::VERBOSITY_DEBUG]);
            $leaks($tester->getDisplay() . $tester->getErrorOutput());
            self::assertNotSame('', $tester->getDisplay(), $name);
        }
        foreach ($this->logger->records as $record) {
            $leaks($record['message'] . serialize($record['context']));
        }
        self::assertNotEmpty($this->events);
        foreach ($this->events as $event) {
            $leaks(serialize($event) . var_export($event, true));
        }

        // The database holds the hash in push_token_hash only: no other column, config or sample carries either.
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT * FROM uptime_monitor') as $row) {
            unset($row['push_token_hash']);
            $leaks(implode('|', array_map(static fn (mixed $value): string => (string) $value, $row)));
        }

        // The applier on its own: the wrapped database exception keeps nothing of the original.
        try {
            $this->isolatedApplier('failing', $hash, $token)->apply(
                (new MonitorFileParser())->parse(MonitorFileParserTest::file([MonitorFileParserTest::entry(['key' => 'x', 'url_key' => 'fedcba9876543210fedcba9876543210'])])),
                new ExternalPushTokens(['x' => $hash]),
                false,
                false,
            );
            self::fail('The failing flush must surface.');
        } catch (ApplyFailedException $failed) {
            for ($e = $failed; $e instanceof Throwable; $e = $e->getPrevious()) {
                $leaks($e->getMessage() . $e->getTraceAsString() . $e);
            }
            self::assertNull($failed->getPrevious());
            self::assertSame(RuntimeException::class, $failed->getCauseClass());
        }

        // A real unique violation on flush (a row inserted behind the plan's back, e.g. by another tenant that is not
        // locked) is a conflict to retry since 1.19.1 (audit FI-I6): exit 6, rolled back, nothing of the cause kept.
        try {
            $this->isolatedApplier('racing')->apply(
                (new MonitorFileParser())->parse(MonitorFileParserTest::file([MonitorFileParserTest::entry(['key' => 'y', 'url_key' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaab'])])),
                new ExternalPushTokens(['y' => $hash]),
                false,
                false,
            );
            self::fail('The violation must surface.');
        } catch (ApplyRefusedException $refused) {
            $leaks($refused->getMessage() . $refused);
            self::assertNull($refused->getPrevious());
            self::assertSame([['', MonitorApplier::CONCURRENT_CONFLICT, ApplyError::EXIT_CONFLICT]], array_map(static fn (ApplyError $error): array => [$error->path, $error->code, $error->exitCode], $refused->getErrors()));
        }
    }

    public function testTheTokenNeverTravelsOnTheCommandLine(): void
    {
        $definition = (new ApplyMonitorsCommand(new MonitorFileParser(), new PushTokenFileReader(), $this->applier()))->getDefinition();
        self::assertSame([], $definition->getArguments());
        self::assertSame(['file', 'token-file', 'tenant', 'dry-run', 'prune', 'format'], array_keys($definition->getOptions()));

        $marker      = $this->token();
        $application = new Application();
        $application->setAutoExit(false);
        $command = new ApplyMonitorsCommand(new MonitorFileParser(), new PushTokenFileReader(), $this->applier());
        $application->addCommand($command);
        $output = new BufferedOutput();
        $code   = $application->run(new ArrayInput(['command' => 'nowo:uptime:apply-monitors', '--file' => 'x.json', '--token' => $marker]), $output);
        $text   = $output->fetch();
        self::assertSame(2, $code, 'an unknown option is a usage error');
        self::assertStringNotContainsString($marker, $text);
        /** @var array<string, mixed> $json */
        $json = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([false, [['path' => '', 'code' => 'invalid_usage']]], [$json['ok'], $json['errors']]);

        // A positional argument is refused the same way.
        $tester = $this->tester();
        self::assertSame(2, $tester->execute(['--file' => 'x.json', 'extra' => $marker]));
        self::assertStringNotContainsString($marker, $tester->getDisplay());
    }

    public function testErrorsOfEveryClassAreReportedTogether(): void
    {
        // An invalid file and a weak token: both are listed, the exit code is the lowest class (3).
        [$code, $json] = $this->runCommand(
            ['--file' => $this->monitorsFile([MonitorFileParserTest::entry(['interval_seconds' => 5])]), '--token-file' => '-'],
            json_encode(['little-vps-1.resources' => str_repeat('ab', 16)], JSON_THROW_ON_ERROR),
        );
        self::assertSame(3, $code);
        self::assertSame(['out_of_range', 'token_too_short'], array_column($json['errors'], 'code'));
        self::assertSame('token-file:/?', $json['errors'][1]['path'], 'no entry key is echoed without a valid monitors file');

        // A missing tenant and a conflict of a tenant that exists: both listed, exit 5, nothing written.
        $other = $this->tenants->findOneBySlug(self::OTHER);
        self::assertInstanceOf(Tenant::class, $other);
        $holder = (new Monitor($other, 'Other team', MonitorType::Push, 'push'))->setPushKey(self::URL_KEY);
        $this->em->persist($holder);
        $this->em->flush();
        $file = $this->path(json_encode(['version' => 1, 'tenants' => [
            self::TENANT                           => ['monitors' => [MonitorFileParserTest::entry()]],
            '00000000-0000-4000-8000-00000000000f' => ['monitors' => []],
        ]], JSON_THROW_ON_ERROR), 0o644);
        [$code, $json] = $this->runCommand(['--file' => $file]);
        self::assertSame(5, $code);
        self::assertSame(['tenant_not_found', 'url_key_taken'], array_column($json['errors'], 'code'));
        self::assertNull($this->fileMonitor());
    }

    public function testADisabledTenantIsReportedAndAnotherTenantsUrlKeyIsRefusedWithoutNamingIt(): void
    {
        $other = $this->tenants->findOneBySlug(self::OTHER);
        self::assertInstanceOf(Tenant::class, $other);
        $other->setEnabled(false);
        $this->em->flush();

        [$code, $json] = $this->runCommand(['--file' => $this->monitorsFile([MonitorFileParserTest::entry()], self::OTHER)]);
        self::assertSame(0, $code);
        self::assertSame([['tenant' => self::OTHER, 'tenant_enabled' => false]], $json['tenants']);

        // The URL key now belongs to a monitor of OTHER: a TENANT entry asking for it is refused, and nothing in the
        // output names OTHER.
        $tester = $this->tester();
        $code   = $tester->execute(['--file' => $this->monitorsFile([MonitorFileParserTest::entry(['key' => 'mine'])])], ['capture_stderr_separately' => true]);
        self::assertSame(6, $code);
        self::assertStringContainsString('url_key_taken', $tester->getDisplay());
        self::assertStringNotContainsString(self::OTHER, $tester->getDisplay() . $tester->getErrorOutput());
    }

    public function testAnUnchangedRunAndADryRunIssueNoWrite(): void
    {
        $file = $this->monitorsFile([MonitorFileParserTest::entry()]);

        $this->statements->statements = [];
        [, $planned]                  = $this->runCommand(['--file' => $file, '--dry-run' => true]);
        self::assertSame([], $this->statements->writes(), 'a dry run writes nothing');
        self::assertSame(0, SqliteEntityManagerFactory::count($this->em, 'uptime_monitor'));

        [, $applied] = $this->runCommand(['--file' => $file]);
        self::assertNotSame([], $this->statements->writes());
        $strip = static fn (array $json): array => array_map(static fn (array $row): array => ['id' => null] + $row, $json['created']);
        self::assertSame($strip($planned), $strip($applied), 'the dry run planned what the run did');

        $this->statements->statements = [];
        [$code, $json]                = $this->runCommand(['--file' => $file]);
        self::assertSame([0, 1], [$code, count($json['unchanged'])]);
        self::assertSame([], $this->statements->writes(), 'an unchanged run issues no INSERT, UPDATE or DELETE');
    }

    public function testAUrlKeyChangeFreesTheOldKey(): void
    {
        $this->runCommand(['--file' => $this->monitorsFile([MonitorFileParserTest::entry()])]);
        [, $json] = $this->runCommand(['--file' => $this->monitorsFile([MonitorFileParserTest::entry(['url_key' => 'fedcba9876543210fedcba9876543210'])])]);
        self::assertSame(['url_key'], $json['updated'][0]['changed']);
        $this->em->clear();
        self::assertNull($this->monitors->findOneBy(['pushKey' => self::URL_KEY]));

        // The freed key can be taken by another entry at once.
        [$code] = $this->runCommand(['--file' => $this->monitorsFile([
            MonitorFileParserTest::entry(['url_key' => 'fedcba9876543210fedcba9876543210']),
            MonitorFileParserTest::entry(['key' => 'second']),
        ])]);
        self::assertSame(0, $code);
    }

    public function testAConflictFoundOnlyUnderTheLockRefusesWithExit6(): void
    {
        [$code, $json] = $this->runCommand(['--file' => $this->monitorsFile([MonitorFileParserTest::entry()])], '', $this->isolatedApplier('locking'));

        self::assertSame([6, [['path' => '/tenants/' . self::TENANT . '/monitors/0/url_key', 'code' => 'url_key_taken']]], [$code, $json['errors']]);
        self::assertSame('warning', $this->logger->records[count($this->logger->records) - 1]['level'] ?? null);
    }

    public function testAFailingListenerAfterTheCommitIsAWarningAndTheOtherTenantsStillGetTheirEvent(): void
    {
        $file = $this->path(json_encode(['version' => 1, 'tenants' => [
            self::TENANT => ['monitors' => [MonitorFileParserTest::entry()]],
            self::OTHER  => ['monitors' => [MonitorFileParserTest::entry(['key' => 'other', 'url_key' => 'fedcba9876543210fedcba9876543210'])]],
        ]], JSON_THROW_ON_ERROR), 0o644);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MonitorsAppliedEvent::class, function (object $event): void {
            self::assertInstanceOf(MonitorsAppliedEvent::class, $event);
            if ($event->tenantSlug === self::TENANT) {
                throw new RuntimeException('audit sink down');
            }
            $this->events[] = $event;
        });

        [$code, $json, $stderr] = $this->runCommand(['--file' => $file], '', new MonitorApplier($this->em, $this->monitors, $this->tenants, null, $dispatcher));

        self::assertSame(0, $code, 'the changes are committed');
        self::assertTrue($json['ok']);
        self::assertSame([['tenant' => self::TENANT, 'code' => MonitorApplier::WARNING_LISTENER_FAILED]], $json['warnings']);
        self::assertSame(['little-vps-1.resources', 'other'], array_column($json['created'], 'key'));
        self::assertNotNull($this->fileMonitor());
        self::assertNotNull($this->fileMonitor('other'));
        self::assertSame([self::OTHER], array_map(static fn (object $event): string => $event instanceof MonitorsAppliedEvent ? $event->tenantSlug : $event::class, $this->events), 'the later tenant still gets its event');
        self::assertStringContainsString('1 warning(s)', $stderr);
        self::assertStringNotContainsString('audit sink down', $stderr . $json['warnings'][0]['code']);
        $warnings = array_values(array_filter($this->logger->records, static fn (array $record): bool => $record['level'] === 'warning'));
        self::assertCount(1, $warnings);
        self::assertSame([self::TENANT, RuntimeException::class], [$warnings[0]['context']['tenant'], $warnings[0]['context']['error']]);
    }

    public function testPruneDeletesTheHistoryAndOnlyInTheScopedTenant(): void
    {
        $file = $this->path(json_encode(['version' => 1, 'tenants' => [
            self::TENANT => ['monitors' => [MonitorFileParserTest::entry()]],
            self::OTHER  => ['monitors' => [MonitorFileParserTest::entry(['key' => 'other', 'url_key' => 'fedcba9876543210fedcba9876543210'])]],
        ]], JSON_THROW_ON_ERROR), 0o644);
        $this->runCommand(['--file' => $file]);
        $mine = $this->fileMonitor();
        self::assertInstanceOf(Monitor::class, $mine);
        SqliteEntityManagerFactory::addCheckResults($this->em, $mine, 3, new DateTimeImmutable('2026-01-01 00:00:00'));
        SqliteEntityManagerFactory::addAggregatesAndIncidents($this->em, $mine, 2);

        $empty = $this->path(json_encode(['version' => 1, 'tenants' => [
            self::TENANT => ['monitors' => []],
            self::OTHER  => ['monitors' => []],
        ]], JSON_THROW_ON_ERROR), 0o644);
        [, $json] = $this->runCommand(['--file' => $empty, '--prune' => true, '--tenant' => self::TENANT]);
        self::assertSame(['little-vps-1.resources'], array_column($json['pruned'], 'key'));
        self::assertNotNull($this->fileMonitor('other'), 'the other tenant is out of scope');
        foreach (['uptime_check_result', 'uptime_check_aggregate', 'uptime_incident'] as $table) {
            self::assertSame(0, SqliteEntityManagerFactory::count($this->em, $table), $table);
        }
    }

    public function testTheIngestRouteAcceptsTheFileTokenAndRefusesTheRotatedOne(): void
    {
        $first  = $this->token();
        $second = $this->token();
        $file   = $this->monitorsFile([MonitorFileParserTest::entry()]);
        $clock  = new MockClock(new DateTimeImmutable('2026-11-05 10:00:00'));
        $push   = fn (string $token): int => (new PushController($this->monitors, new PushCredentials('kernel-secret'), $this->em, [
            'enabled'                  => true,
            'max_body_bytes'           => 8192,
            'signature_window_seconds' => 300,
            'min_push_seconds'         => 10,
        ], $clock))(self::URL_KEY, Request::create('/uptime-push/' . self::URL_KEY, 'POST', server: [
            'CONTENT_TYPE'       => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], content: '{"cpu_pct": 12}'))->getStatusCode();

        $this->runCommand(['--file' => $file, '--token-file' => '-'], json_encode(['little-vps-1.resources' => $first], JSON_THROW_ON_ERROR));
        self::assertSame(202, $push($first));

        $this->runCommand(['--file' => $file, '--token-file' => '-'], json_encode(['little-vps-1.resources' => $second], JSON_THROW_ON_ERROR));
        $this->em->clear();
        $clock->sleep(120);
        self::assertSame(401, $push($first), 'the rotated token stops at once');
        self::assertSame(202, $push($second));
    }

    /**
     * R2 (§4.8): on every failure path the exception, its whole previous chain, its trace and its Messenger-style
     * flattening carry neither the token nor its hash.
     */
    public function testNoExceptionOfAnyFailurePathCarriesTokenMaterial(): void
    {
        $token  = $this->token();
        $hash   = hash('sha256', $token);
        $spec   = (new MonitorFileParser())->parse(MonitorFileParserTest::file([MonitorFileParserTest::entry(), MonitorFileParserTest::entry(['key' => 'second', 'url_key' => 'fedcba9876543210fedcba9876543210'])]));
        $reader = new PushTokenFileReader();
        $stdin  = static function (string $content) {
            $stream = fopen('php://memory', 'r+');
            self::assertNotFalse($stream);
            fwrite($stream, $content);
            rewind($stream);

            return $stream;
        };
        $map  = static fn (array $tokens): string => json_encode($tokens, JSON_THROW_ON_ERROR);
        $link = sys_get_temp_dir() . '/uptime-apply-link-' . bin2hex(random_bytes(4));
        symlink($this->path($map(['little-vps-1.resources' => $token])), $link);
        $this->files[] = $link;
        $tty           = new class implements TokenSourceSystem {
            public function lstat(string $path): array|false
            {
                return false;
            }

            public function open(string $path): mixed
            {
                return false;
            }

            public function fstat(mixed $handle): array|false
            {
                return false;
            }

            public function isTty(mixed $stream): bool
            {
                return true;
            }

            public function effectiveUid(): ?int
            {
                return null;
            }
        };
        $this->em->persist((new Monitor($this->tenant, 'Hand made', MonitorType::Push, 'push'))->setPushKey('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')->setPushTokenHash($hash));
        $this->em->persist((new Monitor($this->tenant, 'Holder', MonitorType::Push, 'push'))->setPushKey(self::URL_KEY));
        $this->em->flush();

        $paths = [
            'short token'       => static fn (): ExternalPushTokens => $reader->read('-', $spec, $stdin($map(['second' => substr($token, 0, 62)]))),
            'low entropy'       => static fn (): ExternalPushTokens => $reader->read('-', $spec, $stdin($map(['second' => substr($token, 0, 8) . str_repeat('0', 56)]))),
            'uppercase'         => static fn (): ExternalPushTokens => $reader->read('-', $spec, $stdin($map(['second' => strtoupper($token)]))),
            'file mode'         => fn (): ExternalPushTokens => $reader->read($this->path($map(['second' => $token]), 0o644), $spec, $stdin('')),
            'symlink'           => static fn (): ExternalPushTokens => $reader->read($link, $spec, $stdin('')),
            'too large'         => static fn (): ExternalPushTokens => $reader->read('-', $spec, $stdin($map(['second' => $token]) . str_repeat(' ', 65_536))),
            'bad json'          => static fn (): ExternalPushTokens => $reader->read('-', $spec, $stdin('{"second": "' . $token . '"')),
            'terminal'          => static fn (): ExternalPushTokens => (new PushTokenFileReader($tty))->read('-', $spec, $stdin($map(['second' => $token]))),
            'duplicate token'   => static fn (): ExternalPushTokens => $reader->read('-', $spec, $stdin($map(['second' => $token, 'little-vps-1.resources' => $token]))),
            'stored hash'       => fn (): ApplyResult => $this->applier()->apply($spec->onlyTenant(self::TENANT), new ExternalPushTokens(['second' => $hash]), true, false),
            'file validation'   => static fn (): MonitorFileSpec => (new MonitorFileParser())->parse(MonitorFileParserTest::file([MonitorFileParserTest::entry(['token' => $token])])),
            'unknown tenant'    => fn (): ApplyResult => $this->applier()->apply((new MonitorFileParser())->parse(MonitorFileParserTest::file([], '00000000-0000-4000-8000-00000000000f')), new ExternalPushTokens(), false, false),
            'url key, planning' => fn (): ApplyResult => $this->applier()->apply($spec, new ExternalPushTokens(['little-vps-1.resources' => $hash]), true, false),
            'url key, locked'   => fn (): ApplyResult => $this->isolatedApplier('locking')->apply($spec, new ExternalPushTokens(['little-vps-1.resources' => $hash]), false, false),
            'database failure'  => fn (): ApplyResult => $this->isolatedApplier('failing', $hash, $token)->apply($spec, new ExternalPushTokens(['second' => $hash]), false, false),
        ];
        foreach ($paths as $name => $path) {
            try {
                $path();
                self::fail($name . ' must throw');
            } catch (ApplyRefusedException|ApplyFailedException $thrown) {
                for ($e = $thrown; $e instanceof Throwable; $e = $e->getPrevious()) {
                    $text = $e->getMessage() . $e->getTraceAsString() . $e;
                    self::assertStringNotContainsString($token, $text, $name);
                    self::assertStringNotContainsString($hash, $text, $name);
                }
                self::assertNull($thrown->getPrevious(), $name);
                $flat = json_encode(FlattenException::createFromThrowable($thrown)->toArray(), JSON_THROW_ON_ERROR);
                self::assertStringNotContainsString($token, $flat, $name);
                self::assertStringNotContainsString($hash, $flat, $name);
            }
        }
    }

    public function testAFailureWhileReadingTheDatabaseIsWrappedToo(): void
    {
        // No schema at all: the first tenant lookup fails, in a dry run as in a real one.
        $broken   = SqliteEntityManagerFactory::create(null, false);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($broken);
        $applier = new MonitorApplier($broken, new MonitorRepository($registry), new TenantRepository($registry));
        $spec    = (new MonitorFileParser())->parse(MonitorFileParserTest::file([MonitorFileParserTest::entry()]));

        foreach ([true, false] as $dryRun) {
            try {
                $applier->apply($spec, new ExternalPushTokens(), $dryRun, false);
                self::fail('The lookup must fail.');
            } catch (ApplyFailedException $failed) {
                self::assertNull($failed->getPrevious());
                self::assertStringStartsWith('apply_failed: Doctrine\\DBAL\\', $failed->getMessage());
            }
        }

        [$code, $json] = $this->runCommand(['--file' => $this->monitorsFile([MonitorFileParserTest::entry()]), '--dry-run' => true], '', $applier);
        self::assertSame([1, [['path' => '', 'code' => 'apply_failed']]], [$code, $json['errors']]);
        self::assertSame('error', $this->logger->records[count($this->logger->records) - 1]['level']);
    }

    /**
     * An applier on its own database whose entity manager fails on flush: `failing` throws an exception quoting the
     * token and its hash, `racing` inserts a row that takes the planned URL key first (a real unique violation).
     */
    private function isolatedApplier(string $kind, string $hash = '', string $token = ''): MonitorApplier
    {
        $em = SqliteEntityManagerFactory::create();
        $em->persist(new Tenant(self::TENANT, 'Infra'));
        $em->persist(new Tenant(self::OTHER, 'Other'));
        $em->flush();
        $decorated = match ($kind) {
            'failing' => $this->failingEm($em, $hash, $token),
            'locking' => $this->lockingEm($em),
            default   => $this->racingEm($em),
        };
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($decorated);

        return new MonitorApplier($decorated, new MonitorRepository($registry), new TenantRepository($registry));
    }

    private function failingEm(EntityManagerInterface $em, string $hash, string $token): EntityManagerInterface
    {
        return new class($em, $hash, $token) extends EntityManagerDecorator {
            public function __construct(EntityManagerInterface $wrapped, private readonly string $hash, private readonly string $token)
            {
                parent::__construct($wrapped);
            }

            public function flush(): void
            {
                throw new RuntimeException('SQLSTATE[23000]: Integrity constraint violation with params ["' . $this->hash . '"]', 0, new RuntimeException('driver said: ' . $this->token));
            }
        };
    }

    private function lockingEm(EntityManagerInterface $em): EntityManagerInterface
    {
        return new class($em) extends EntityManagerDecorator {
            public function lock(object $entity, LockMode|int $lockMode, DateTimeInterface|int|null $lockVersion = null): void
            {
                // A UI create takes the planned URL key between the run's start and its tenant lock.
                // Bound with types: PostgreSQL refuses an integer for the boolean column.
                $this->wrapped->getConnection()->executeStatement(
                    "INSERT INTO uptime_monitor (tenant_id, name, type, target, config, interval_seconds, paused, created_at, push_key, origin) VALUES (1, 'ui', 'push', 'push', '{}', 60, ?, '2026-01-01 00:00:00', '0123456789abcdef0123456789abcdef', 'ui')",
                    [false],
                    [ParameterType::BOOLEAN],
                );
                parent::lock($entity, $lockMode, $lockVersion);
            }
        };
    }

    private function racingEm(EntityManagerInterface $em): EntityManagerInterface
    {
        return new class($em) extends EntityManagerDecorator {
            public function flush(): void
            {
                // Another writer takes the URL key between the plan and the flush.
                // Bound with types: PostgreSQL refuses an integer for the boolean column.
                $this->wrapped->getConnection()->executeStatement(
                    "INSERT INTO uptime_monitor (tenant_id, name, type, target, config, interval_seconds, paused, created_at, push_key, origin) VALUES (1, 'race', 'push', 'push', '{}', 60, ?, '2026-01-01 00:00:00', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaab', 'ui')",
                    [false],
                    [ParameterType::BOOLEAN],
                );
                parent::flush();
            }
        };
    }
}
