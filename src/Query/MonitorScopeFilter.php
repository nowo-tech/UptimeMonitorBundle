<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

use Doctrine\ORM\QueryBuilder;

use function implode;
use function sprintf;

/**
 * Narrows a query builder to a {@see MonitorScope} (since 1.36). The builder joins the monitor as `m` and its tenant
 * as `t`. The SQL comparison follows the column collation, so callers re-check every row with
 * {@see MonitorScope::covers()}.
 *
 * @internal
 */
final class MonitorScopeFilter
{
    /**
     * Adds the scope condition (tenants OR grants) and the id filter; the scope must not be empty.
     */
    public static function apply(QueryBuilder $builder, MonitorScope $scope): QueryBuilder
    {
        $any = [];
        if ($scope->tenantSlugs !== []) {
            $any[] = 't.slug IN (:scopeSlugs)';
            $builder->setParameter('scopeSlugs', $scope->tenantSlugs);
        }
        $byTenant = [];
        foreach ($scope->grantedMonitors as $ref) {
            $byTenant[$ref->tenantSlug][] = $ref->monitorId;
        }
        $index = 0;
        foreach ($byTenant as $slug => $ids) {
            $any[] = sprintf('(t.slug = :grantSlug%1$d AND m.id IN (:grantIds%1$d))', $index);
            $builder->setParameter('grantSlug' . $index, (string) $slug);
            $builder->setParameter('grantIds' . $index, $ids);
            ++$index;
        }
        $builder->andWhere('(' . implode(' OR ', $any) . ')');
        if ($scope->monitorIds !== null) {
            $builder->andWhere('m.id IN (:scopeIds)')->setParameter('scopeIds', $scope->monitorIds);
        }

        return $builder;
    }
}
