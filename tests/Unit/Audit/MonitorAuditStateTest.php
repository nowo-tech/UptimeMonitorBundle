<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Audit;

use Nowo\UptimeMonitorBundle\Audit\MonitorAuditState;
use Nowo\UptimeMonitorBundle\Audit\MonitorFieldChange;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

use function array_map;
use function json_encode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

#[CoversClass(MonitorAuditState::class)]
#[CoversClass(MonitorFieldChange::class)]
final class MonitorAuditStateTest extends TestCase
{
    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return list<array{field: string, before?: mixed, after?: mixed, secret?: string}>
     */
    private function diff(array $before, array $after): array
    {
        return array_map(static fn (MonitorFieldChange $change): array => $change->toArray(), MonitorAuditState::diff(
            $before === [] ? [] : MonitorAuditState::fromFields($before),
            $after === [] ? [] : MonitorAuditState::fromFields($after),
        ));
    }

    public function testAGroupHasNoTargetAndTheParentIsRecordedById(): void
    {
        $tenant = new Tenant('main', 'Main');
        $group  = new Monitor($tenant, 'Group', MonitorType::Group, 'Group');
        (new ReflectionProperty(Monitor::class, 'id'))->setValue($group, 7);

        $state = MonitorAuditState::fromFields(['type' => MonitorType::Group, 'name' => 'Group', 'target' => 'Group']);
        self::assertArrayNotHasKey('target', $state);

        self::assertContains(['field' => 'parent_id', 'before' => null, 'after' => 7], $this->diff(['type' => MonitorType::Tcp, 'target' => 'db:5432'], ['type' => MonitorType::Tcp, 'target' => 'db:5432', 'parent' => $group]));
    }

    public function testUnknownConfigKeysFailClosedAndPlainValuesAreBounded(): void
    {
        $changes = $this->diff(['type' => MonitorType::Https, 'name' => 'A', 'config' => ['webhook_secret' => 'one', 'timeout' => new stdClass(), 'tags' => ['a'], 'failure_streak' => 1]], ['type' => MonitorType::Https, 'name' => str_repeat('n', 250), 'config' => ['webhook_secret' => 'two', 'timeout' => 10, 'tags' => ['a', 'b'], 'failure_streak' => 9]]);

        self::assertContains(['field' => 'config.webhook_secret', 'secret' => 'replaced'], $changes);
        self::assertContains(['field' => 'timeout', 'before' => null, 'after' => 10], $changes);
        self::assertContains(['field' => 'tags', 'before' => ['a'], 'after' => ['a', 'b']], $changes);
        self::assertContains(['field' => 'name', 'before' => 'A', 'after' => str_repeat('n', MonitorAuditState::MAX_TEXT) . '…'], $changes);
        self::assertStringNotContainsString('failure_streak', json_encode($changes, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('two', json_encode($changes, JSON_THROW_ON_ERROR));
    }

    public function testUrlsKeepTheirOriginOnlyAndFailClosed(): void
    {
        // An ambiguous URL shows scheme://*** (1.32), an unreadable one no origin at all: both details are markers.
        self::assertSame([
            ['field' => 'target', 'before' => 'https://a.test', 'after' => 'https://***'],
            ['field' => 'target_details', 'secret' => 'replaced'],
        ], $this->diff(['type' => MonitorType::Https, 'target' => 'https://a.test/'], ['type' => MonitorType::Https, 'target' => 'https://admin:4242/rest@host/']));
        self::assertSame([
            ['field' => 'target', 'before' => 'https://a.test', 'after' => null],
            ['field' => 'target_details', 'secret' => 'replaced'],
        ], $this->diff(['type' => MonitorType::Https, 'target' => 'https://a.test'], ['type' => MonitorType::Https, 'target' => 'Server=db;Password=x;']));
        // The same origin and nothing else: no change; a removed proxy clears its details.
        self::assertSame([], $this->diff(['type' => MonitorType::Https, 'target' => 'https://a.test'], ['type' => MonitorType::Https, 'target' => 'https://a.test/']));
        self::assertSame([
            ['field' => 'proxy', 'before' => 'http://p.test:3128', 'after' => null],
            ['field' => 'proxy_details', 'secret' => 'cleared'],
        ], $this->diff(['type' => MonitorType::Https, 'config' => ['proxy' => 'http://u:p@p.test:3128']], ['type' => MonitorType::Https, 'config' => ['proxy' => '']]));
    }

    public function testSecretsWithTheSameOrNoValueAreNotReported(): void
    {
        self::assertSame([], $this->diff(['type' => MonitorType::Https, 'config' => ['headers' => ['X-Key' => 'k'], 'body' => '']], ['type' => MonitorType::Https, 'config' => ['headers' => ['X-Key' => 'k'], 'body' => null]]));
    }

    public function testStoredChangesReadBackWithoutTrustingValuesNextToAMarker(): void
    {
        $cleared = MonitorFieldChange::fromArray(['field' => 'headers', 'secret' => 'cleared', 'before' => 'leak']);
        self::assertTrue($cleared->isSecret());
        self::assertSame(['field' => 'headers', 'secret' => 'cleared'], $cleared->toArray());
        self::assertSame(MonitorFieldChange::REPLACED, MonitorFieldChange::fromArray(['field' => 'body', 'secret' => 'other'])->secret);

        $plain = MonitorFieldChange::fromArray(['before' => 1, 'after' => 2]);
        self::assertFalse($plain->isSecret());
        self::assertSame(['field' => '', 'before' => 1, 'after' => 2], $plain->toArray());
    }
}
