<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\UptimeMonitorBundle\Doctrine\KeysetBatchDelete;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

use function array_filter;
use function array_slice;
use function count;
use function is_string;
use function str_starts_with;

#[CoversClass(KeysetBatchDelete::class)]
final class KeysetBatchDeleteTest extends TestCase
{
    private EntityManagerInterface $em;

    private Monitor $monitor;

    protected function setUp(): void
    {
        $this->em      = SqliteEntityManagerFactory::create();
        $tenant        = new Tenant('main', 'Main');
        $this->monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');
        $this->em->persist($tenant);
        $this->em->persist($this->monitor);
        $this->em->flush();
    }

    public function testEmptyTableDeletesNothing(): void
    {
        self::assertSame(0, KeysetBatchDelete::run($this->em, CheckResult::class));
    }

    public function testDeletesEveryRowAcrossSeveralBatchesWithoutFilter(): void
    {
        SqliteEntityManagerFactory::addCheckResults($this->em, $this->monitor, 2500, new DateTimeImmutable('-1 day'));

        self::assertSame(2500, KeysetBatchDelete::run($this->em, CheckResult::class));
        self::assertSame(0, SqliteEntityManagerFactory::count($this->em, 'uptime_check_result'));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function batchSizes(): iterable
    {
        yield 'exact multiple of the batch' => [4, 2];
        yield 'short last batch' => [5, 2];
        yield 'batch larger than the set' => [5, 1000];
        yield 'batch size clamped to one' => [3, 0];
    }

    #[DataProvider('batchSizes')]
    public function testFilterKeepsRowsItDoesNotMatch(int $old, int $batchSize): void
    {
        SqliteEntityManagerFactory::addCheckResults($this->em, $this->monitor, $old, new DateTimeImmutable('-10 days'));
        SqliteEntityManagerFactory::addCheckResults($this->em, $this->monitor, 3, new DateTimeImmutable('-1 hour'));
        // Interleave: more expired rows after the recent ones (higher ids), so the keyset must step past kept rows.
        SqliteEntityManagerFactory::addCheckResults($this->em, $this->monitor, $old, new DateTimeImmutable('-10 days'));

        $cutoff  = new DateTimeImmutable('-5 days');
        $deleted = KeysetBatchDelete::run(
            $this->em,
            CheckResult::class,
            static function (QueryBuilder $qb, string $alias) use ($cutoff): void {
                $qb->andWhere($alias . '.checkedAt < :cutoff')->setParameter('cutoff', $cutoff);
            },
            $batchSize,
        );

        self::assertSame(2 * $old, $deleted);
        self::assertSame(3, SqliteEntityManagerFactory::count($this->em, 'uptime_check_result'));
    }

    public function testEachBatchIsAShortDeleteByIdList(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $sql = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (isset($context['sql']) && is_string($context['sql'])) {
                    $this->sql[] = $context['sql'];
                }
            }
        };

        $em      = SqliteEntityManagerFactory::create((new DbalConfiguration())->setMiddlewares([new LoggingMiddleware($logger)]));
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');
        $em->persist($tenant);
        $em->persist($monitor);
        $em->flush();
        SqliteEntityManagerFactory::addCheckResults($em, $monitor, 25, new DateTimeImmutable('-1 day'));
        $start = count($logger->sql);

        self::assertSame(25, KeysetBatchDelete::run($em, CheckResult::class, null, 10));

        $statements = array_slice($logger->sql, $start);
        $deletes    = array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'DELETE'));
        $selects    = array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'SELECT'));
        self::assertCount(3, $deletes);
        self::assertCount(3, $selects);
        foreach ($deletes as $sql) {
            self::assertStringContainsString(' IN (', $sql);
        }
        foreach ($selects as $sql) {
            self::assertStringContainsString('LIMIT 10', $sql);
        }
    }

    public function testBatchSizeFromConfig(): void
    {
        self::assertSame(1000, KeysetBatchDelete::batchSizeFrom([]));
        self::assertSame(1000, KeysetBatchDelete::batchSizeFrom(['purge_batch_size' => 0]));
        self::assertSame(1000, KeysetBatchDelete::batchSizeFrom(['purge_batch_size' => 'abc']));
        self::assertSame(250, KeysetBatchDelete::batchSizeFrom(['purge_batch_size' => 250]));
        self::assertSame(50, KeysetBatchDelete::batchSizeFrom(['purge_batch_size' => '50']));
    }
}
