<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Ui;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

use function array_reverse;
use function implode;
use function in_array;
use function is_string;
use function sprintf;
use function usort;

/**
 * Sort of the monitor page check log (since 1.30, `web_ui.monitor_show.log.sortable`): column (`status`, `time`,
 * `latency`, `code`) and direction from the query (`log_sort`, `log_dir`); newest first by default. An immutable value
 * built per request (no state in a service).
 */
final readonly class MonitorLogSort
{
    /** Sortable columns, in table order. */
    public const COLUMNS = ['status', 'time', 'latency', 'code'];

    /** Event keys of the columns. */
    private const KEYS = ['status' => 'status', 'time' => 'checked_at', 'latency' => 'latency_ms', 'code' => 'status_code'];

    /**
     * @param string $column One of {@see self::COLUMNS}
     * @param string $direction `asc` or `desc`
     *
     * @throws InvalidArgumentException On an unknown column or direction
     */
    public function __construct(
        public string $column = 'time',
        public string $direction = 'desc',
    ) {
        if (!in_array($column, self::COLUMNS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown check log sort column "%s" (%s).', $column, implode(', ', self::COLUMNS)));
        }
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException(sprintf('Unknown check log sort direction "%s" (asc, desc).', $direction));
        }
    }

    /**
     * The sort of the request's `log_sort` / `log_dir` query; unknown values fall back to newest first.
     */
    public static function fromRequest(?Request $request): self
    {
        $column    = $request?->query->get('log_sort');
        $direction = $request?->query->get('log_dir');
        $column    = is_string($column) && in_array($column, self::COLUMNS, true) ? $column : 'time';
        $default   = $column === 'time' ? 'desc' : 'asc';

        return new self($column, in_array($direction, ['asc', 'desc'], true) ? (string) $direction : $default);
    }

    /**
     * Whether this is the default sort (newest first).
     */
    public function isDefault(): bool
    {
        return $this->column === 'time' && $this->direction === 'desc';
    }

    /**
     * Sorts the check log (newest first as given); null keeps it. Ties keep the newest first; a missing code sorts
     * last in both directions.
     *
     * @param list<array<string, mixed>> $events Newest first
     *
     * @return list<array<string, mixed>>
     */
    public static function apply(array $events, ?self $sort): array
    {
        if (!$sort instanceof self || $sort->isDefault()) {
            return $events;
        }
        if ($sort->column === 'time') {
            return array_reverse($events);
        }
        $key  = self::KEYS[$sort->column];
        $sign = $sort->direction === 'asc' ? 1 : -1;
        usort($events, static function (array $a, array $b) use ($key, $sign): int {
            $left  = $a[$key] ?? null;
            $right = $b[$key] ?? null;
            if ($left === null || $right === null) {
                return ($left === null ? 1 : 0) - ($right === null ? 1 : 0);
            }

            return $sign * ($left <=> $right);
        });

        return $events;
    }

    /**
     * Query parameters that keep this sort across log pages ([] for the default sort).
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return $this->isDefault() ? [] : ['log_sort' => $this->column, 'log_dir' => $this->direction];
    }

    /**
     * Query of a column header link: the other direction for the current column, else the column's first direction
     * (time newest first, the others ascending).
     *
     * @return array{log_sort: string, log_dir: string}
     */
    public function linkQuery(string $column): array
    {
        if ($column === $this->column) {
            return ['log_sort' => $column, 'log_dir' => $this->direction === 'asc' ? 'desc' : 'asc'];
        }

        return ['log_sort' => $column, 'log_dir' => $column === 'time' ? 'desc' : 'asc'];
    }

    /**
     * Template view (`log_sort`): column, direction and the header link of each column.
     *
     * @param array<string, string> $links URL by column
     *
     * @return array{column: string, direction: string, links: array<string, string>}
     */
    public function view(array $links): array
    {
        return ['column' => $this->column, 'direction' => $this->direction, 'links' => $links];
    }
}
