<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Apply;

use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Apply\ApplyFailedException;
use Nowo\UptimeMonitorBundle\Apply\ApplyRefusedException;
use Nowo\UptimeMonitorBundle\Apply\ExternalPushTokens;
use Nowo\UptimeMonitorBundle\Apply\MonitorApplier;
use Nowo\UptimeMonitorBundle\Apply\MonitorFileParser;
use Nowo\UptimeMonitorBundle\Apply\PushTokenFileReader;
use Nowo\UptimeMonitorBundle\Command\ApplyMonitorsCommand;
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
use Nowo\UptimeMonitorBundle\Tests\Unit\Apply\MonitorFileParserTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Throwable;

use function array_column;
use function array_keys;
use function array_map;
use function bin2hex;
use function chmod;
use function file_put_contents;
use function hash;
use function json_decode;
use function json_encode;
use function random_bytes;
use function serialize;
use function substr;
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
    /** @var list<object> */
    private array $events = [];
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->em = SqliteEntityManagerFactory::create();
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

        // A real constraint violation on flush (a row inserted behind the plan's back) is wrapped the same way.
        try {
            $this->isolatedApplier('racing')->apply(
                (new MonitorFileParser())->parse(MonitorFileParserTest::file([MonitorFileParserTest::entry(['key' => 'y', 'url_key' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaab'])])),
                new ExternalPushTokens(['y' => $hash]),
                false,
                false,
            );
            self::fail('The violation must surface.');
        } catch (ApplyFailedException $failed) {
            $leaks($failed->getMessage() . $failed);
            self::assertNull($failed->getPrevious());
        } catch (ApplyRefusedException $refused) {
            self::fail('Unexpected refusal: ' . $refused->getMessage());
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
        $code   = $application->run(new ArrayInput(['command' => 'nowo:uptime:apply-monitors', '--token' => $marker]), $output);
        self::assertNotSame(0, $code);
        self::assertStringNotContainsString($marker, $output->fetch());
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
        $decorated = $kind === 'failing' ? $this->failingEm($em, $hash, $token) : $this->racingEm($em);
        $registry  = $this->createStub(ManagerRegistry::class);
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

    private function racingEm(EntityManagerInterface $em): EntityManagerInterface
    {
        return new class($em) extends EntityManagerDecorator {
            public function flush(): void
            {
                // Another writer takes the URL key between the plan and the flush.
                $this->wrapped->getConnection()->executeStatement(
                    "INSERT INTO uptime_monitor (tenant_id, name, type, target, config, interval_seconds, paused, created_at, push_key, origin) VALUES (1, 'race', 'push', 'push', '{}', 60, 0, '2026-01-01 00:00:00', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaab', 'ui')",
                );
                parent::flush();
            }
        };
    }
}
