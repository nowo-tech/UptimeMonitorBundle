<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Search;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;

use function mb_stripos;
use function mb_strtolower;
use function preg_replace;
use function trim;

/**
 * The candidate query of monitor search (since 1.32): one SQL query (tenant joined, no N+1) with an escaped
 * `LOWER(...) LIKE ... ESCAPE '!'` over the name and, for monitors that have a target, the stored target. Because the stored
 * target may hold userinfo or a query string, a row is kept only when the needle also matches the name or the
 * displayed origin ({@see MonitorSearchUrl}); a needle that only occurs in a credential finds nothing. The SQL filter reads the stored target, so a needle that spans the
 * userinfo (`https://billing.example.com` against `https://user:pw@billing.example.com`) does not find that monitor;
 * a host or name fragment does. The scan is
 * bounded by `$scanLimit` rows, ordered by name then id. Visibility is not decided here ({@see MonitorSearchVisibility}).
 */
final readonly class MonitorSearchQuery
{
    public function __construct(private MonitorRepository $monitors)
    {
    }

    /**
     * @param string $needle Search text (trimmed; an empty text finds nothing)
     * @param int $scanLimit Maximum number of rows read
     * @param string|null $tenantSlug Restrict to this tenant; null searches every tenant
     *
     * @return list<Monitor>
     */
    public function find(string $needle, int $scanLimit, ?string $tenantSlug = null): array
    {
        $needle = trim($needle);
        if ($needle === '' || $scanLimit < 1) {
            return [];
        }

        $builder = $this->monitors->createQueryBuilder('m')
            ->addSelect('t')
            ->innerJoin('m.tenant', 't')
            ->andWhere("LOWER(m.name) LIKE :like ESCAPE '!' OR (m.type NOT IN (:noTarget) AND LOWER(m.target) LIKE :like ESCAPE '!')")
            ->setParameter('like', '%' . self::escapeLike(mb_strtolower($needle)) . '%')
            ->setParameter('noTarget', [MonitorType::Group->value, MonitorType::Push->value])
            ->orderBy('m.name', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->setMaxResults($scanLimit);

        if ($tenantSlug !== null) {
            $builder->andWhere('t.slug = :slug')->setParameter('slug', $tenantSlug);
        }

        /** @var list<Monitor> $rows */
        $rows = $builder->getQuery()->getResult();

        $kept = [];
        foreach ($rows as $monitor) {
            if (mb_stripos($monitor->getName(), $needle) !== false
                || (MonitorSearchMapper::hasTarget($monitor->getType()) && mb_stripos(MonitorSearchUrl::display($monitor->getTarget()), $needle) !== false)) {
                $kept[] = $monitor;
            }
        }

        return $kept;
    }

    /**
     * Escapes `!`, `%` and `_` for a `LIKE ... ESCAPE '!'`.
     */
    public static function escapeLike(string $value): string
    {
        return (string) preg_replace('/[!%_]/', '!$0', $value);
    }
}
