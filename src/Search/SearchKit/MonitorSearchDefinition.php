<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Search\SearchKit;

use Doctrine\ORM\QueryBuilder;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchHit;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchMapper;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchTenantResolverInterface;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchVisibility;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function ctype_digit;
use function is_string;
use function max;
use function min;
use function sprintf;

/**
 * SearchKit group `uptime_monitors` (since 1.32): monitors by name and by the origin of their target.
 *
 * The tenant comes from the bundle's tenant resolver ({@see MonitorSearchTenantResolverInterface}); the SQL filter is
 * that tenant, ordered by name. `view` is checked per candidate by the bundle's own access checker
 * ({@see MonitorSearchVisibility}), so host rules apply, and SearchKit then sends its host visibility event for the
 * page. A result carries the name, the origin of the target and the last status; never the config, headers, auth or
 * push credentials. SQL matches the stored target, so every word is verified again against the name and the displayed
 * origin: a word found only in a credential, a path or a query string finds nothing.
 */
final class MonitorSearchDefinition extends EntitySearchDefinition
{
    public const GROUP = 'uptime_monitors';

    /**
     * Creates the definition.
     *
     * @param MonitorRepository $monitors Monitor repository of the bundle
     * @param MonitorSearchTenantResolverInterface $tenants Tenant resolver of the viewer
     * @param MonitorSearchVisibility $visibility The bundle's access check for search
     * @param MonitorSearchMapper $mapper Monitor to result mapping
     * @param UrlGeneratorInterface $urls URL generator
     * @param TranslatorInterface|null $translator Translator (null: English label)
     * @param int $maxCandidates Rows read and checked per search
     */
    public function __construct(
        private readonly MonitorRepository $monitors,
        private readonly MonitorSearchTenantResolverInterface $tenants,
        private readonly MonitorSearchVisibility $visibility,
        private readonly MonitorSearchMapper $mapper,
        private readonly UrlGeneratorInterface $urls,
        private readonly ?TranslatorInterface $translator = null,
        private readonly int $maxCandidates = self::DEFAULT_MAX_CANDIDATES,
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
     * The viewer's tenant slug, from the tenant resolver; null means no results.
     */
    public function tenant(SearchQuery $query): ?string
    {
        return $this->tenants->resolve((string) $query->userIdentifier);
    }

    /**
     * Whether the viewer may use monitor search (`canAccessDashboard()` of the access checker).
     */
    public function featureEnabled(int|string $tenant, SearchQuery $query): bool
    {
        return $this->visibility->canSearch();
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
     * Monitors of the tenant, ordered by name then id.
     */
    public function baseQuery(int|string $tenant, SearchQuery $query): QueryBuilder
    {
        return $this->monitors->createQueryBuilder('m')
            ->addSelect('t')
            ->innerJoin('m.tenant', 't')
            ->where('t.slug = :nowo_uptime_tenant')
            ->setParameter('nowo_uptime_tenant', (string) $tenant)
            ->orderBy('m.name', 'ASC')
            ->addOrderBy('m.id', 'ASC');
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
