<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Audit;

use Nowo\UptimeMonitorBundle\Event\MonitorChangedEvent;

use function array_key_exists;
use function is_string;

/**
 * One field change of a monitor (since 1.33). A plain field carries its value before and after (null when it had none,
 * e.g. on create or delete). A write-only field (headers, body, basic-auth username and password, URL / proxy userinfo,
 * path and query, push URL key and token, any config key the bundle does not know) carries only {@see self::REPLACED}
 * or {@see self::CLEARED}: never its value, never a hash of it.
 */
final readonly class MonitorFieldChange
{
    /** A write-only field got a value, or another one. */
    public const REPLACED = 'replaced';

    /** A write-only field lost its value. */
    public const CLEARED = 'cleared';

    /**
     * Creates a field change.
     *
     * @param string $field Field name ({@see MonitorAuditState})
     * @param mixed $before Plain value before (null for a write-only field)
     * @param mixed $after Plain value after (null for a write-only field)
     * @param string|null $secret {@see self::REPLACED} or {@see self::CLEARED} for a write-only field; null for a plain one
     */
    public function __construct(
        public string $field,
        public mixed $before = null,
        public mixed $after = null,
        public ?string $secret = null,
    ) {
    }

    /**
     * A change of a write-only field: only the marker, never the values.
     */
    public static function secret(string $field, bool $cleared): self
    {
        return new self($field, secret: $cleared ? self::CLEARED : self::REPLACED);
    }

    /**
     * Whether the field is write-only (the change carries a marker, no values).
     */
    public function isSecret(): bool
    {
        return $this->secret !== null;
    }

    /**
     * The change as stored in the history and carried by {@see MonitorChangedEvent}:
     * `{field, before, after}` or `{field, secret}`.
     *
     * @return array{field: string, before?: mixed, after?: mixed, secret?: string}
     */
    public function toArray(): array
    {
        if ($this->secret !== null) {
            return ['field' => $this->field, 'secret' => $this->secret];
        }

        return ['field' => $this->field, 'before' => $this->before, 'after' => $this->after];
    }

    /**
     * Reads a stored change back; an unknown marker reads as {@see self::REPLACED} (values are never trusted from a
     * row that carries a marker).
     *
     * @param array<mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $field = is_string($row['field'] ?? null) ? $row['field'] : '';
        if (array_key_exists('secret', $row)) {
            return self::secret($field, $row['secret'] === self::CLEARED);
        }

        return new self($field, $row['before'] ?? null, $row['after'] ?? null);
    }
}
