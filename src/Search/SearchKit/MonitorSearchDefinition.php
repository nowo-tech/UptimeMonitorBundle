<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Search\SearchKit;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Event\MonitorSearchScopeEvent;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchHit;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchMapper;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchTenantResolverInterface;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchTenants;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchTenantsResolverInterface;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchVisibility;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;
use function ctype_digit;
use function hash;
use function implode;
use function is_string;
use function max;
use function min;
use function sort;
use function sprintf;

use const SORT_STRING;

/**
 * SearchKit group `uptime_monitors` (since 1.32): monitors by name and by the origin of their target.
 *
 * The tenants come from the bundle's tenant resolver (since 1.33 a list, {@see MonitorSearchTenantsResolverInterface};
 * a 1.32 single-tenant resolver still works), narrowed by {@see MonitorSearchScopeEvent}
 * and the scope of the search box, and kept only where the viewer has the search permission
 * ({@see MonitorSearchVisibility::canSearchTenant()}, no longer the dashboard one): {@see MonitorSearchTenants}. The SQL
 * filter is that list (`t.slug IN (...)`, before SearchKit's limit), ordered by name. `view` is checked per candidate by the bundle's own access checker
 * ({@see MonitorSearchVisibility}), so host rules apply, and SearchKit then sends its host visibility event for the
 * page. A result carries the name, the origin of the target and the last status; never the config, headers, auth or
 * push credentials. SQL matches the stored target, so every word is verified again against the name and the displayed
 * origin: a word found only in a credential, a path or a query string finds nothing.
 */
final class MonitorSearchDefinition extends EntitySearchDefinition
{
    public const GROUP = 'uptime_monitors';

    /** Prefix of the SearchKit tenant of a search over several tenants (since 1.33): `tenants:<sha256 of the sorted slugs>`. */
    public const TENANTS_PREFIX = 'tenants:';

    /**
     * Creates the definition.
     *
     * @param MonitorRepository $monitors Monitor repository of the bundle
     * @param MonitorSearchTenantResolverInterface|MonitorSearchTenantsResolverInterface $tenants Tenant resolver of the viewer (since 1.33 also a list resolver)
     * @param MonitorSearchVisibility $visibility The bundle's access check for search
     * @param MonitorSearchMapper $mapper Monitor to result mapping
     * @param UrlGeneratorInterface $urls URL generator
     * @param TranslatorInterface|null $translator Translator (null: English label)
     * @param int $maxCandidates Rows read and checked per search
     * @param MonitorSearchTenants|null $scope The tenants of a search (since 1.33; null = built from $tenants and $visibility)
     */
    public function __construct(
        private readonly MonitorRepository $monitors,
        private readonly MonitorSearchTenantResolverInterface|MonitorSearchTenantsResolverInterface $tenants,
        private readonly MonitorSearchVisibility $visibility,
        private readonly MonitorSearchMapper $mapper,
        private readonly UrlGeneratorInterface $urls,
        private readonly ?TranslatorInterface $translator = null,
        private readonly int $maxCandidates = self::DEFAULT_MAX_CANDIDATES,
        private readonly ?MonitorSearchTenants $scope = null,
    ) {
    }

    /**
     * The group key `uptime_monitors`.
     */
    public function group(): string
    {
        return self::GROUP;
    }

    /**
     * The translated group label (`search.group`).
     */
    public function label(SearchQuery $query): string
    {
        return $this->translator?->trans('search.group', [], 'NowoUptimeMonitorBundle', $query->locale) ?? 'Monitors';
    }

    /**
     * UX Icons name of the group rows.
     */
    public function icon(): string
    {
        return 'tabler:activity-heartbeat';
    }

    /**
     * The SearchKit tenant: the slug when the search covers one tenant, `tenants:<sha256 of the sorted slugs>` when it
     * covers several (it never means "all"), null when it covers none (no tenant resolved, all narrowed away, or no
     * search permission): no results.
     */
    public function tenant(SearchQuery $query): ?string
    {
        return self::tenantLabel($this->tenantsOf($query));
    }

    /**
     * The SearchKit tenant of a list of tenant slugs (see tenant()).
     *
     * @param list<string> $tenants
     */
    public static function tenantLabel(array $tenants): ?string
    {
        if (count($tenants) <= 1) {
            return $tenants[0] ?? null;
        }
        sort($tenants, SORT_STRING);

        return self::TENANTS_PREFIX . hash('sha256', implode("\n", $tenants));
    }

    /**
     * The search permission was checked per tenant by tenant() (since 1.33; up to 1.32 `canAccessDashboard()`).
     */
    public function featureEnabled(int|string $tenant, SearchQuery $query): bool
    {
        return true;
    }

    /**
     * The scope of the search box is applied by {@see MonitorSearchTenants} inside baseQuery(), in SQL (since 1.33):
     * `tenant:<slug>`, or a host scope a {@see MonitorSearchScopeEvent} listener
     * accepted; any other scope already left no tenant.
     */
    public function applyScope(DbalQueryBuilder|QueryBuilder $queryBuilder, string $scope, int|string $tenant, SearchQuery $query): bool
    {
        return true;
    }

    /**
     * The monitor name only: the stored target may hold credentials, so SQL never matches it.
     */
    public function searchableFields(): array
    {
        return ['m.name'];
    }

    /**
     * Rows read and access-checked per search (`search_kit.max_candidates`, 1-1000).
     */
    public function maxCandidates(): int
    {
        return max(1, min(1000, $this->maxCandidates));
    }

    /**
     * Subject type of the host visibility event.
     */
    public function subjectType(): string
    {
        return 'uptime_monitor';
    }

    /**
     * The id field of baseQuery(), for recents revalidation.
     */
    public function idField(): string
    {
        return 'm.id';
    }

    /**
     * The monitor id of a recent that points at a monitor page; null for anything else (client input).
     */
    public function referenceId(SearchReference $reference): ?string
    {
        $id = $reference->routeParameters['id'] ?? null;

        return $reference->route === 'nowo_uptime_monitor_show' && is_string($id) && ctype_digit($id) ? $id : null;
    }

    /**
     * Monitors of the tenants of the search (resolved again for this call, in SQL), ordered by name then id; none when
     * the search covers no tenant, or when the tenants resolved now no longer match the label of tenant() (the resolver
     * and the listeners run once for tenant() and once here, per search page and per revalidated recent).
     */
    public function baseQuery(int|string $tenant, SearchQuery $query): QueryBuilder
    {
        $tenants = $this->tenantsOf($query);
        if (self::tenantLabel($tenants) !== (string) $tenant) {
            // The tenants changed between tenant() and this call (the resolver and the listeners run again): nothing.
            $tenants = [];
        }

        $queryBuilder = $this->monitors->createQueryBuilder('m')
            ->addSelect('t')
            ->innerJoin('m.tenant', 't')
            ->where($tenants === [] ? '1 = 0' : 't.slug IN (:nowo_uptime_tenants)')
            ->orderBy('m.name', 'ASC')
            ->addOrderBy('m.id', 'ASC');
        if ($tenants !== []) {
            $queryBuilder->setParameter('nowo_uptime_tenants', $tenants, ArrayParameterType::STRING);
        }

        return $queryBuilder;
    }

    /**
     * The tenant slugs this search covers.
     *
     * @return list<string>
     */
    private function tenantsOf(SearchQuery $query): array
    {
        $scope = $this->scope ?? new MonitorSearchTenants($this->tenants, $this->visibility);

        return $scope->resolve($query->userIdentifier, $query->scope);
    }

    /**
     * Keeps the candidates the access checker lets the viewer see.
     */
    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        $visible = [];
        foreach ($rows as $row) {
            if ($row instanceof Monitor && $this->visibility->canSee($row)) {
                $visible[] = $row;
            }
        }

        return $visible;
    }

    /**
     * The result row: name, monitor page link, origin and status.
     */
    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        /** @var Monitor $row */
        $hit = $this->mapper->map($row, true);

        return new SearchItem(
            $hit->name,
            $this->urls->generate('nowo_uptime_monitor_show', ['tenantSlug' => $hit->tenantSlug, 'id' => $hit->id]),
            $this->subtitle($hit, $query->locale),
            null,
            null,
            self::GROUP,
        );
    }

    /**
     * No "see all" link: no page of the bundle lists monitors by a search text.
     */
    public function seeAllUrl(SearchQuery $query): ?string
    {
        return null;
    }

    private function subtitle(MonitorSearchHit $hit, ?string $locale): string
    {
        $status = match (true) {
            $hit->paused          => $this->status('paused', $locale),
            $hit->status !== null => $this->status($hit->status, $locale),
            default               => '',
        };

        return match (true) {
            $hit->url !== '' && $status !== '' => sprintf('%s · %s', $hit->url, $status),
            $hit->url !== ''                   => $hit->url,
            default                            => $status,
        };
    }

    /**
     * The translated status label (`status.<value>`), the raw value without a translator.
     */
    private function status(string $value, ?string $locale): string
    {
        return $this->translator?->trans('status.' . $value, [], 'NowoUptimeMonitorBundle', $locale) ?? $value;
    }
}
