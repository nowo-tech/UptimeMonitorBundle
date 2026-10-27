<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Model;

use Nowo\UptimeMonitorBundle\Enum\CheckStatus;

use function array_slice;
use function count;
use function is_array;
use function is_string;
use function mb_scrub;
use function mb_strlen;
use function mb_substr;

/**
 * Result of a single monitor check execution (before persistence).
 */
final readonly class CheckResultDto
{
    /** Longest stored check message (characters). */
    public const MAX_MESSAGE_LENGTH = 2000;

    /** Longest stored string inside the metadata (characters). */
    public const MAX_METADATA_STRING_LENGTH = 1000;

    /** Most entries kept per metadata array (e.g. DNS records). */
    public const MAX_METADATA_ITEMS = 50;

    /** Deepest metadata nesting kept; deeper arrays are dropped. */
    public const MAX_METADATA_DEPTH = 4;

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public CheckStatus $status,
        public int $latencyMs,
        public ?int $statusCode = null,
        public ?string $message = null,
        public ?array $metadata = null,
    ) {
    }

    /**
     * Copy whose message and metadata are valid UTF-8 and bounded (SEC-004 N1): check data comes from remote targets
     * (DNS TXT records, transport errors) and must never make the result unstorable — a failing flush would close the
     * entity manager for the whole scan.
     */
    public function sanitized(): self
    {
        /** @var array<string, mixed>|null $metadata keys stay strings: only their content is scrubbed */
        $metadata = $this->metadata !== null ? self::sanitizeArray($this->metadata, 1) : null;

        return new self(
            $this->status,
            $this->latencyMs,
            $this->statusCode,
            $this->message !== null ? self::sanitizeString($this->message, self::MAX_MESSAGE_LENGTH) : null,
            $metadata,
        );
    }

    private static function sanitizeString(string $value, int $maxLength): string
    {
        $value = mb_scrub($value, 'UTF-8');

        return mb_strlen($value, 'UTF-8') > $maxLength ? mb_substr($value, 0, $maxLength, 'UTF-8') . '…' : $value;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private static function sanitizeArray(array $values, int $depth): array
    {
        if (count($values) > self::MAX_METADATA_ITEMS) {
            $values = array_slice($values, 0, self::MAX_METADATA_ITEMS, true);
        }

        $clean = [];
        foreach ($values as $key => $value) {
            $key = is_string($key) ? self::sanitizeString($key, self::MAX_METADATA_STRING_LENGTH) : $key;
            if (is_array($value)) {
                if ($depth >= self::MAX_METADATA_DEPTH) {
                    continue;
                }
                $value = self::sanitizeArray($value, $depth + 1);
            } elseif (is_string($value)) {
                $value = self::sanitizeString($value, self::MAX_METADATA_STRING_LENGTH);
            }
            $clean[$key] = $value;
        }

        return $clean;
    }
}
