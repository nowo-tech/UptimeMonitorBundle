<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Search\SearchKit;

use Doctrine\ORM\QueryBuilder;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Search\Query\SearchWords;
use Nowo\SearchKitBundle\Search\Query\SeeAllLink;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchHit;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchMapper;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchTenantResolverInterface;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchUrl;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchVisibility;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;
use function max;
use function mb_stripos;
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

    public function group(): string
    {
        return self::GROUP;
    }

    public function label(SearchQuery $query): string
    {
        return $this->translator?->trans('search.group', [], 'NowoUptimeMonitorBundle', $query->locale) ?? 'Monitors';
    }

    public function icon(): string
    {
        return 'tabler:activity-heartbeat';
    }

    public function tenant(SearchQuery $query): ?string
    {
        return $this->tenants->resolve((string) $query->userIdentifier);
    }

    public function featureEnabled(int|string $tenant, SearchQuery $query): bool
    {
        return $this->visibility->canSearch();
    }

    public function searchableFields(): array
    {
        return ['m.name', 'm.target'];
    }

    public function maxCandidates(): int
    {
        return max(1, min(1000, $this->maxCandidates));
    }

    public function subjectType(): string
    {
        return 'uptime_monitor';
    }

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

    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        $words   = SearchWords::words($query->text);
        $visible = [];
        foreach ($rows as $row) {
            if ($row instanceof Monitor && $this->matches($row, $words) && $this->visibility->canSee($row)) {
                $visible[] = $row;
            }
        }

        return $visible;
    }

    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        /** @var Monitor $row */
        $hit = $this->mapper->map($row, true);

        return new SearchItem(
            $hit->name,
            $this->urls->generate('nowo_uptime_monitor_show', ['tenantSlug' => $hit->tenantSlug, 'id' => $hit->id]),
            $this->subtitle($hit),
            null,
            null,
            self::GROUP,
        );
    }

    public function seeAllUrl(SearchQuery $query): ?string
    {
        $slug = $this->tenant($query);
        if (!is_string($slug) || $slug === '') {
            return null;
        }

        try {
            return SeeAllLink::build($this->urls, 'nowo_uptime_dashboard', ['tenantSlug' => $slug], $query);
        } catch (RoutingException) {
            return null;
        }
    }

    /**
     * @param list<string> $words
     */
    private function matches(Monitor $monitor, array $words): bool
    {
        $origin = MonitorSearchMapper::hasTarget($monitor->getType()) ? MonitorSearchUrl::display($monitor->getTarget()) : '';
        foreach ($words as $word) {
            if (mb_stripos($monitor->getName(), $word) === false && ($origin === '' || mb_stripos($origin, $word) === false)) {
                return false;
            }
        }

        return true;
    }

    private function subtitle(MonitorSearchHit $hit): string
    {
        return match (true) {
            $hit->url !== '' && $hit->status !== null => sprintf('%s · %s', $hit->url, $hit->status),
            $hit->url !== ''                          => $hit->url,
            default                                   => $hit->status ?? '',
        };
    }
}
