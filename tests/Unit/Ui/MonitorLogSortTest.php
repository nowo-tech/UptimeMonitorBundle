<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Ui;

use Nowo\UptimeMonitorBundle\Ui\MonitorLogSort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

use function array_column;
use function substr;

#[CoversClass(MonitorLogSort::class)]
final class MonitorLogSortTest extends TestCase
{
    /**
     * @return list<array<string, mixed>> newest first
     */
    private function events(): array
    {
        return [
            ['status' => 'up', 'checked_at' => '2026-10-26T10:03:00+00:00', 'latency_ms' => 30, 'status_code' => 200, 'message' => null],
            ['status' => 'down', 'checked_at' => '2026-10-26T10:02:00+00:00', 'latency_ms' => 900, 'status_code' => null, 'message' => 'timeout'],
            ['status' => 'degraded', 'checked_at' => '2026-10-26T10:01:00+00:00', 'latency_ms' => 30, 'status_code' => 503, 'message' => null],
            ['status' => 'up', 'checked_at' => '2026-10-26T10:00:00+00:00', 'latency_ms' => 10, 'status_code' => 200, 'message' => null],
        ];
    }

    public function testTheRequestPicksColumnAndDirection(): void
    {
        self::assertTrue(MonitorLogSort::fromRequest(null)->isDefault());
        self::assertTrue(MonitorLogSort::fromRequest(Request::create('/', 'GET', ['log_sort' => 'bogus', 'log_dir' => 'sideways']))->isDefault());
        $latency = MonitorLogSort::fromRequest(Request::create('/', 'GET', ['log_sort' => 'latency']));
        self::assertSame(['latency', 'asc'], [$latency->column, $latency->direction], 'a number column starts ascending');
        $code = MonitorLogSort::fromRequest(Request::create('/', 'GET', ['log_sort' => 'code', 'log_dir' => 'desc']));
        self::assertSame(['code', 'desc'], [$code->column, $code->direction]);
        self::assertSame(['log_sort' => 'code', 'log_dir' => 'desc'], $code->query());
        self::assertSame([], (new MonitorLogSort())->query());
    }

    public function testSortingKeepsTiesNewestFirstAndMissingCodesLast(): void
    {
        $events = $this->events();
        self::assertSame($events, MonitorLogSort::apply($events, null));
        self::assertSame($events, MonitorLogSort::apply($events, new MonitorLogSort()));
        self::assertSame(['10:00', '10:01', '10:02', '10:03'], $this->times(MonitorLogSort::apply($events, new MonitorLogSort('time', 'asc'))));
        self::assertSame(['10:00', '10:03', '10:01', '10:02'], $this->times(MonitorLogSort::apply($events, new MonitorLogSort('latency', 'asc'))));
        self::assertSame(['10:02', '10:03', '10:01', '10:00'], $this->times(MonitorLogSort::apply($events, new MonitorLogSort('latency', 'desc'))));
        self::assertSame(['10:03', '10:00', '10:01', '10:02'], $this->times(MonitorLogSort::apply($events, new MonitorLogSort('code', 'asc'))));
        self::assertSame(['10:01', '10:03', '10:00', '10:02'], $this->times(MonitorLogSort::apply($events, new MonitorLogSort('code', 'desc'))));
        self::assertSame(['degraded', 'down', 'up', 'up'], array_column(MonitorLogSort::apply($events, new MonitorLogSort('status', 'asc')), 'status'));
    }

    public function testHeaderLinksToggleTheCurrentColumn(): void
    {
        $sort = new MonitorLogSort('latency', 'asc');
        self::assertSame(['log_sort' => 'latency', 'log_dir' => 'desc'], $sort->linkQuery('latency'));
        self::assertSame(['log_sort' => 'time', 'log_dir' => 'desc'], $sort->linkQuery('time'));
        self::assertSame(['log_sort' => 'status', 'log_dir' => 'asc'], $sort->linkQuery('status'));
        self::assertSame(['log_sort' => 'latency', 'log_dir' => 'asc'], (new MonitorLogSort('latency', 'desc'))->linkQuery('latency'));
        self::assertSame(['column' => 'latency', 'direction' => 'asc', 'links' => ['time' => '/t']], $sort->view(['time' => '/t']));
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<string>
     */
    private function times(array $events): array
    {
        $times = [];
        foreach ($events as $event) {
            $times[] = substr((string) $event['checked_at'], 11, 5);
        }

        return $times;
    }
}
