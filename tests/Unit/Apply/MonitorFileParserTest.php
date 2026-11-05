<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Apply;

use Nowo\UptimeMonitorBundle\Apply\ApplyError;
use Nowo\UptimeMonitorBundle\Apply\ApplyRefusedException;
use Nowo\UptimeMonitorBundle\Apply\MonitorFileParser;
use Nowo\UptimeMonitorBundle\Apply\MonitorFileSpec;
use Nowo\UptimeMonitorBundle\Apply\PushMonitorSpec;
use Nowo\UptimeMonitorBundle\Push\PushThresholds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function hash;
use function json_encode;
use function sprintf;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

#[CoversClass(MonitorFileParser::class)]
#[CoversClass(MonitorFileSpec::class)]
#[CoversClass(PushMonitorSpec::class)]
#[CoversClass(ApplyRefusedException::class)]
#[CoversClass(ApplyError::class)]
final class MonitorFileParserTest extends TestCase
{
    public const TENANT = '00000000-0000-4000-8000-000000000000';
    public const OTHER  = '00000000-0000-4000-8000-000000000001';

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function entry(array $overrides = []): array
    {
        return $overrides + [
            'key'              => 'little-vps-1.resources',
            'type'             => 'push',
            'name'             => 'little-vps-1 — resources',
            'url_key'          => '0123456789abcdef0123456789abcdef',
            'interval_seconds' => 60,
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function json(array $document): string
    {
        return json_encode($document, JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<mixed> $monitors
     */
    public static function file(array $monitors, string $tenant = self::TENANT): string
    {
        return self::json(['version' => 1, 'tenants' => [$tenant => ['monitors' => $monitors]]]);
    }

    /**
     * @return list<array{0: string, 1: string}> [path, code]
     */
    private function errorsOf(string $json): array
    {
        try {
            (new MonitorFileParser())->parse($json);
        } catch (ApplyRefusedException $refused) {
            self::assertSame(3, $refused->getExitCode());

            return array_map(static fn (ApplyError $error): array => [$error->path, $error->code], $refused->getErrors());
        }
        self::fail('The file was accepted.');
    }

    public function testAFullEntryIsParsedWithItsDefaults(): void
    {
        $json = self::json([
            '$schema' => 'https://github.com/nowo-tech/UptimeMonitorBundle/schema/apply-monitors.v1.json',
            'version' => 1,
            'tenants' => [
                self::TENANT => ['monitors' => [self::entry([
                    'project'                        => 'infra',
                    'description'                    => 'Pushed every minute.',
                    'grace_seconds'                  => 120,
                    'retries'                        => 1,
                    'resend_notification_after_down' => 3,
                    'paused'                         => true,
                    'thresholds'                     => ['disk' => ['warn' => 80, 'critical' => 90.5], 'swap' => ['warn' => null, 'critical' => null], 'load' => ['warn' => 2, 'critical' => 4]],
                ]), self::entry(['key' => 'second', 'url_key' => 'fedcba9876543210fedcba9876543210'])]],
                self::OTHER => ['monitors' => []],
            ],
        ]);

        $spec = (new MonitorFileParser())->parse($json);

        self::assertSame(hash('sha256', $json), $spec->sha256);
        self::assertTrue($spec->hasTenant(self::OTHER));
        self::assertFalse($spec->hasTenant('00000000-0000-4000-8000-00000000000f'));
        self::assertSame(2, $spec->count());
        $full = $spec->entries()[0];
        self::assertSame(['little-vps-1.resources', 'infra', 'Pushed every minute.', 120, 1, 3, true], [$full->key, $full->project, $full->description, $full->graceSeconds, $full->retries, $full->resendNotificationAfterDown, $full->paused]);
        self::assertSame([80.0, 90.5], $full->thresholds['disk']);
        self::assertSame([null, null], $full->thresholds['swap']);
        self::assertSame([2.0, 4.0], $full->thresholds['load']);
        self::assertSame(PushThresholds::DEFAULTS['memory'], $full->thresholds['memory']);
        self::assertSame('/tenants/' . self::TENANT . '/monitors/0/url_key', $full->path('url_key'));

        $plain = $spec->entries()[1];
        self::assertSame(['', '', null, 0, 0, null], [$plain->project, $plain->description, $plain->graceSeconds, $plain->retries, $plain->resendNotificationAfterDown, $plain->paused]);
        self::assertSame('/tenants/' . self::TENANT . '/monitors/1', $plain->path());

        $only = $spec->onlyTenant(self::OTHER);
        self::assertSame([self::OTHER => []], $only->tenants);
        self::assertSame($spec->sha256, $only->sha256);
        self::assertSame([], $spec->onlyTenant('00000000-0000-4000-8000-00000000000f')->entries());
    }

    public function testTheRootIsChecked(): void
    {
        self::assertSame([['', 'invalid_json']], $this->errorsOf('{"version": 1,'));
        self::assertSame([['', 'invalid_json']], $this->errorsOf(str_repeat('[', 20) . str_repeat(']', 20)));
        self::assertSame([['', 'invalid_type']], $this->errorsOf('[]'));
        self::assertSame(
            [['/extra', 'unknown_key'], ['/$schema', 'invalid_type'], ['/version', 'missing_key'], ['/tenants', 'missing_key']],
            $this->errorsOf(self::json(['$schema' => 1, 'extra' => true])),
        );
        self::assertSame([['/version', 'unsupported_version'], ['/tenants', 'invalid_type']], $this->errorsOf(self::json(['version' => 2, 'tenants' => []])));
        self::assertSame([['/tenants', 'empty']], $this->errorsOf('{"version": 1, "tenants": {}}'));
        $many = [];
        for ($i = 0; $i < 51; ++$i) {
            $many[sprintf('00000000-0000-4000-8000-%012d', $i)] = ['monitors' => []];
        }
        self::assertSame([['/tenants', 'too_many']], $this->errorsOf(self::json(['version' => 1, 'tenants' => $many])));
    }

    public function testTenantsAreUuidKeyedObjectsWithAMonitorsList(): void
    {
        self::assertSame(
            [['/tenants/Main~1x~0y', 'invalid_tenant'], ['/tenants/' . self::OTHER, 'invalid_type']],
            $this->errorsOf(self::json(['version' => 1, 'tenants' => ['Main/x~y' => ['monitors' => []], self::OTHER => 'x']])),
        );
        $t = '/tenants/' . self::TENANT;
        self::assertSame([[$t . '/name', 'unknown_key'], [$t . '/monitors', 'missing_key']], $this->errorsOf(self::json(['version' => 1, 'tenants' => [self::TENANT => ['name' => 'x']]])));
        self::assertSame([[$t . '/monitors', 'invalid_type']], $this->errorsOf(self::json(['version' => 1, 'tenants' => [self::TENANT => ['monitors' => ['a' => 1]]]])));
        $monitors = [];
        for ($i = 0; $i < 201; ++$i) {
            $monitors[] = self::entry();
        }
        self::assertSame([[$t . '/monitors', 'too_many']], $this->errorsOf(self::file($monitors)));
    }

    public function testEntriesAreValidatedFieldByField(): void
    {
        $m = '/tenants/' . self::TENANT . '/monitors/0';
        self::assertSame([[$m, 'invalid_type']], $this->errorsOf(self::file(['x'])));
        self::assertSame(
            [[$m . '/token', 'unknown_key'], [$m . '/key', 'missing_key'], [$m . '/type', 'missing_key'], [$m . '/name', 'missing_key'], [$m . '/url_key', 'missing_key'], [$m . '/interval_seconds', 'missing_key']],
            $this->errorsOf(self::file([['token' => 'never-here']])),
        );
        self::assertSame(
            [
                [$m . '/key', 'invalid_format'],
                [$m . '/url_key', 'invalid_type'],
                [$m . '/name', 'too_long'],
                [$m . '/project', 'invalid_type'],
                [$m . '/type', 'unsupported_type'],
                [$m . '/interval_seconds', 'out_of_range'],
                [$m . '/grace_seconds', 'invalid_type'],
                [$m . '/retries', 'out_of_range'],
                [$m . '/resend_notification_after_down', 'invalid_type'],
                [$m . '/paused', 'invalid_type'],
                [$m . '/thresholds', 'invalid_type'],
            ],
            $this->errorsOf(self::file([self::entry([
                'key'                            => 'Upper Case',
                'url_key'                        => 123,
                'name'                           => str_repeat('n', 256),
                'project'                        => ['x'],
                'type'                           => 'http',
                'interval_seconds'               => 10,
                'grace_seconds'                  => 1.5,
                'retries'                        => 101,
                'resend_notification_after_down' => '3',
                'paused'                         => 'yes',
                'thresholds'                     => [1],
            ])])),
        );
        self::assertSame([[$m . '/name', 'empty']], $this->errorsOf(self::file([self::entry(['name' => '   '])])));
        self::assertSame([[$m . '/description', 'too_long']], $this->errorsOf(self::file([self::entry(['description' => str_repeat('d', 2001)])])));
        self::assertSame([[$m . '/url_key', 'invalid_format']], $this->errorsOf(self::file([self::entry(['url_key' => 'ABCDEF0123456789ABCDEF0123456789'])])));
    }

    public function testThresholdsAreCheckedPerMetric(): void
    {
        $t = '/tenants/' . self::TENANT . '/monitors/0/thresholds';
        self::assertSame(
            [
                [$t . '/gpu', 'unknown_key'],
                [$t . '/disk', 'invalid_type'],
                [$t . '/cpu/max', 'unknown_key'],
                [$t . '/cpu/critical', 'missing_key'],
                [$t . '/memory/warn', 'invalid_type'],
                [$t . '/memory/critical', 'out_of_range'],
                [$t . '/swap/warn', 'out_of_range'],
                [$t . '/load', 'warn_above_critical'],
            ],
            $this->errorsOf(self::file([self::entry(['thresholds' => [
                'gpu'    => ['warn' => 1, 'critical' => 2],
                'disk'   => 85,
                'cpu'    => ['warn' => 80, 'max' => 1],
                'memory' => ['warn' => '90', 'critical' => 101],
                'swap'   => ['warn' => -1, 'critical' => null],
                'load'   => ['warn' => 3.5, 'critical' => 2],
            ]])])),
        );
        $spec = (new MonitorFileParser())->parse(self::file([self::entry(['thresholds' => ['load' => ['warn' => 150, 'critical' => 300]]])]));
        self::assertSame([150.0, 300.0], $spec->entries()[0]->thresholds['load'], 'load is not a percentage');
    }

    public function testKeysAndUrlKeysAreUniqueAcrossTheFile(): void
    {
        $json = self::json(['version' => 1, 'tenants' => [
            self::TENANT => ['monitors' => [self::entry(), self::entry(['url_key' => 'fedcba9876543210fedcba9876543210'])]],
            self::OTHER  => ['monitors' => [self::entry(['key' => 'other'])]],
        ]]);

        self::assertSame(
            [['/tenants/' . self::TENANT . '/monitors/1/key', 'duplicate_key'], ['/tenants/' . self::OTHER . '/monitors/0/url_key', 'duplicate_url_key']],
            $this->errorsOf($json),
        );
    }

    public function testTheFileIsReadWithinItsSizeLimit(): void
    {
        $parser = new MonitorFileParser();
        $path   = (string) tempnam(sys_get_temp_dir(), 'uptime-apply');
        try {
            file_put_contents($path, self::file([self::entry()]));
            self::assertSame(1, $parser->parseFile($path)->count());

            file_put_contents($path, str_repeat(' ', MonitorFileParser::MAX_BYTES + 1));
            $this->assertRefused(static fn (): MonitorFileSpec => $parser->parseFile($path), 'file_too_large');
        } finally {
            unlink($path);
        }
        $this->assertRefused(static fn (): MonitorFileSpec => $parser->parseFile($path), 'file_not_found');
        $this->assertRefused(static fn (): MonitorFileSpec => $parser->parseFile(sys_get_temp_dir()), 'file_not_found');
    }

    private function assertRefused(callable $call, string $code): void
    {
        try {
            $call();
            self::fail('Expected ' . $code);
        } catch (ApplyRefusedException $refused) {
            self::assertSame($code, $refused->getErrors()[0]->code);
            self::assertSame('apply_refused: ' . $code, $refused->getMessage());
            self::assertNull($refused->getPrevious());
        }
    }
}
