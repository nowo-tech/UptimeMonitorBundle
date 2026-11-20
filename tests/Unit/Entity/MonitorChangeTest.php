<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Audit\MonitorFieldChange;
use Nowo\UptimeMonitorBundle\Entity\MonitorChange;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonitorChange::class)]
final class MonitorChangeTest extends TestCase
{
    public function testARowKeepsWhatItWasCreatedWith(): void
    {
        $tenant = new Tenant('main', 'Main');
        $at     = new DateTimeImmutable('2026-11-20 10:00:00');
        $row    = new MonitorChange($tenant, 7, 'updated', 'ui', 'alice', [['field' => 'method', 'before' => 'GET', 'after' => 'POST'], ['field' => 'body', 'secret' => 'cleared']], $at);

        self::assertNull($row->getId());
        self::assertSame([$tenant, 7, 'updated', 'ui', 'alice', $at], [$row->getTenant(), $row->getMonitorId(), $row->getAction(), $row->getSource(), $row->getActor(), $row->getOccurredAt()]);
        self::assertEquals([new MonitorFieldChange('method', 'GET', 'POST'), MonitorFieldChange::secret('body', true)], $row->getChanges());
    }
}
