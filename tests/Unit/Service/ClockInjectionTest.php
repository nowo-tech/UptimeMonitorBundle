<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Notification\Channel\WebhookNotificationChannel;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Repository\IncidentRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateService;
use Nowo\UptimeMonitorBundle\Service\CheckExecutorService;
use Nowo\UptimeMonitorBundle\Service\DueChecksRunner;
use Nowo\UptimeMonitorBundle\Service\NotificationService;
use Nowo\UptimeMonitorBundle\Service\StatusTransitionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function json_decode;

/**
 * REQ-DI-001: time-dependent services read the injected Symfony clock (frozen with MockClock here).
 */
#[CoversClass(AggregateService::class)]
#[CoversClass(DueChecksRunner::class)]
#[CoversClass(StatusTransitionService::class)]
#[CoversClass(WebhookNotificationChannel::class)]
#[CoversClass(CheckResult::class)]
#[CoversClass(Incident::class)]
final class ClockInjectionTest extends TestCase
{
    private const NOW = '2026-03-15 10:42:17';

    private function monitor(): Monitor
    {
        return new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://example.test');
    }

    public function testAggregateBucketsStartFromTheClockTime(): void
    {
        $repository = $this->createStub(CheckAggregateRepository::class);
        $repository->method('findOneForBucket')->willReturn(null);
        $persisted = [];
        $em        = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        (new AggregateService($em, $repository, ['periods' => ['hour', 'day', 'month', 'year']], new MockClock(self::NOW)))
            ->recordFromCheck($this->monitor(), new CheckResultDto(CheckStatus::Up, 5));

        self::assertContainsOnlyInstancesOf(CheckAggregate::class, $persisted);
        self::assertSame(
            ['2026-03-15 10:00:00', '2026-03-15 00:00:00', '2026-03-01 00:00:00', '2026-01-01 00:00:00'],
            array_map(static fn (CheckAggregate $a): string => $a->getPeriodStart()->format('Y-m-d H:i:s'), $persisted),
        );
        self::assertSame([AggregatePeriod::Hour, AggregatePeriod::Day, AggregatePeriod::Month, AggregatePeriod::Year], array_map(static fn (CheckAggregate $a): AggregatePeriod => $a->getPeriod(), $persisted));
    }

    public function testDueChecksDefaultToTheClockTime(): void
    {
        $seen       = [];
        $repository = $this->createStub(MonitorRepository::class);
        $repository->method('findDue')->willReturnCallback(static function (DateTimeImmutable $now) use (&$seen): array {
            $seen[] = $now->format('Y-m-d H:i:s');

            return [];
        });

        $runner = new DueChecksRunner($repository, (new ReflectionClass(CheckExecutorService::class))->newInstanceWithoutConstructor(), true, new MockClock(self::NOW));

        self::assertSame(0, $runner->runDueChecks());
        self::assertSame(0, $runner->runDueChecks(new DateTimeImmutable('2020-01-01 00:00:00')));
        self::assertSame([self::NOW, '2020-01-01 00:00:00'], $seen);
    }

    public function testIncidentsAndAlertCooldownUseTheClock(): void
    {
        $clock   = new MockClock(self::NOW);
        $monitor = $this->monitor();
        $monitor->setLastKnownStatus(CheckStatus::Up);

        $persisted = [];
        $em        = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $incidents     = $this->createStub(IncidentRepository::class);
        $notifications = $this->createStub(NotificationService::class);
        $notifications->method('notify')->willReturn(1);

        $service = new StatusTransitionService($em, $incidents, $notifications, ['enabled' => true, 'cooldown_seconds' => 60], null, $clock);

        $service->handleAfterCheck($monitor, new CheckResult($monitor, CheckStatus::Down, 1));
        self::assertInstanceOf(Incident::class, $persisted[0]);
        self::assertSame(self::NOW, $persisted[0]->getStartedAt()->format('Y-m-d H:i:s'));
        self::assertSame(self::NOW, $monitor->getLastAlertAt()?->format('Y-m-d H:i:s'));

        // Still inside the cooldown: no new alert time.
        $clock->sleep(30);
        $service->handleAfterCheck($monitor, new CheckResult($monitor, CheckStatus::Up, 1));
        self::assertSame(self::NOW, $monitor->getLastAlertAt()?->format('Y-m-d H:i:s'));

        // Past the cooldown: the recovery resolves the open incident at clock time.
        $incidents->method('findOpenForMonitor')->willReturn($persisted[0]);
        $monitor->setLastKnownStatus(CheckStatus::Down);
        $clock->sleep(60);
        $service->handleAfterCheck($monitor, new CheckResult($monitor, CheckStatus::Up, 1));
        self::assertSame('2026-03-15 10:43:47', $monitor->getLastAlertAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-15 10:43:47', $persisted[0]->getEndedAt()?->format('Y-m-d H:i:s'));
    }

    public function testWebhookTimestampComesFromTheClock(): void
    {
        $body   = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = $options['body'];

            return new MockResponse('ok');
        });
        $channel = new WebhookNotificationChannel(
            ['enabled' => true, 'webhook' => ['enabled' => true, 'url' => 'https://hooks.example.test/']],
            $client,
            WebhookNotificationChannel::CHANNEL_GENERIC,
            new MockClock(self::NOW . ' UTC'),
        );

        $channel->send(new UptimeAlert($this->monitor(), UptimeAlert::TRANSITION_DOWN, CheckStatus::Down, CheckStatus::Up));

        self::assertSame('2026-03-15T10:42:17+00:00', json_decode((string) $body, true)['timestamp'] ?? null);
    }

    public function testEntitiesKeepUsableDefaultTimestamps(): void
    {
        $monitor = $this->monitor();
        $before  = new DateTimeImmutable('-1 second');

        self::assertGreaterThan($before, (new CheckResult($monitor, CheckStatus::Up, 1))->getCheckedAt());
        self::assertGreaterThan($before, (new Incident($monitor, CheckStatus::Down))->getStartedAt());
        self::assertSame(
            '2001-02-03 04:05:06',
            (new CheckResult($monitor, CheckStatus::Up, 1, null, null, null, new DateTimeImmutable('2001-02-03 04:05:06')))->getCheckedAt()->format('Y-m-d H:i:s'),
        );
    }
}
