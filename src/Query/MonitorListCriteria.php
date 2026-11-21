<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Form\TenantFormType;

use function array_values;
use function is_string;
use function preg_match;

/**
 * What {@see MonitorQueryInterface::list()} and {@see MonitorQueryInterface::count()} read (since 1.35).
 *
 * The scope is explicit and nothing else widens it: the tenants in `$tenantSlugs` (all their monitors), plus the single
 * monitors in `$grantedMonitors`, each bound to its own tenant. An empty scope reads nothing (no query runs). A slug
 * that is not a valid tenant slug (lowercase letters, digits and hyphens) is dropped, so a slug compares exactly even
 * on a case- or accent-insensitive collation.
 *
 * The filters only narrow that scope: `$monitorIds` (null = any), `$type`, `$status`, `$paused`. Groups are left out
 * unless `$includeGroups` is true.
 */
final readonly class MonitorListCriteria
{
    /** @var list<string> */
    public array $tenantSlugs;

    /** @var list<MonitorRef> */
    public array $grantedMonitors;

    /**
     * @param list<string> $tenantSlugs Tenants whose monitors are in scope (invalid slugs are dropped)
     * @param list<MonitorRef> $grantedMonitors Single monitors in scope, each with its tenant (invalid slugs are dropped)
     * @param list<int>|null $monitorIds Only these monitor ids (null = no restriction; an empty list matches nothing)
     * @param MonitorType|null $type Only this type
     * @param MonitorListStatus|null $status Only this status ({@see MonitorListStatus})
     * @param bool|null $paused Only paused (true) or not paused (false) monitors
     * @param bool $includeGroups Whether group monitors are listed
     */
    public function __construct(
        array $tenantSlugs = [],
        array $grantedMonitors = [],
        public ?array $monitorIds = null,
        public ?MonitorType $type = null,
        public ?MonitorListStatus $status = null,
        public ?bool $paused = null,
        public bool $includeGroups = false,
    ) {
        $slugs = [];
        foreach ($tenantSlugs as $slug) {
            if (self::isValidSlug($slug)) {
                $slugs[$slug] = $slug;
            }
        }
        $this->tenantSlugs = array_values($slugs);

        $granted = [];
        foreach ($grantedMonitors as $ref) {
            if (self::isValidSlug($ref->tenantSlug)) {
                $granted[$ref->monitorId . '@' . $ref->tenantSlug] = $ref;
            }
        }
        $this->grantedMonitors = array_values($granted);
    }

    /**
     * Whether nothing is in scope (the query answers nothing without reading).
     */
    public function isEmpty(): bool
    {
        return ($this->tenantSlugs === [] && $this->grantedMonitors === []) || $this->monitorIds === [];
    }

    /**
     * Whether a monitor of that tenant and id is in scope (exact comparison).
     */
    public function covers(string $tenantSlug, int $monitorId): bool
    {
        foreach ($this->tenantSlugs as $slug) {
            if ($slug === $tenantSlug) {
                return true;
            }
        }
        foreach ($this->grantedMonitors as $ref) {
            if ($ref->monitorId === $monitorId && $ref->tenantSlug === $tenantSlug) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the value is a tenant slug ({@see TenantFormType::SLUG_PATTERN}).
     */
    public static function isValidSlug(mixed $slug): bool
    {
        return is_string($slug) && $slug !== '' && preg_match(TenantFormType::SLUG_PATTERN, $slug) === 1;
    }
}
