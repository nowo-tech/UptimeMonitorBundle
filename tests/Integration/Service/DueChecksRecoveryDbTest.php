<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Service;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Check\DnsCheckRunner;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\CheckExecutorService;
use Nowo\UptimeMonitorBundle\Service\DueChecksRunner;
use Nowo\UptimeMonitorBundle\Tests\Support\OfflineDns;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * SEC-004 N1: a check result Doctrine cannot store must not poison the scan for every tenant.
 */
#[Group('db')]
#[CoversClass(DueChecksRunner::class)]
#[CoversClass(CheckExecutorService::class)]
#[CoversClass(CheckResultDto::class)]
final class DueChecksRecoveryDbTest extends TestCase
{
    use SyncDispatcherTestTrait;

    private EntityManager $first;
    private SwappableEntityManager $em;
    private ManagerRegistry $registry;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $first = SqliteEntityManagerFactory::create();
        self::assertInstanceOf(EntityManager::class, $first);
        $this->first = $first;
        $this->em    = new SwappableEntityManager($first);

        $em     = $this->em;
        $events = $first->getEventManager();
        self::assertInstanceOf(EventManager::class, $events);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $registry->method('getManager')->willReturn($em);
        $registry->method('getManagers')->willReturn(['default' => $em]);
        // Like DoctrineBundle's lazy manager: the same service object continues with a fresh manager.
        $registry->method('resetManager')->willReturnCallback(static function () use ($em, $first, $events): EntityManagerInterface {
            $em->swap(new EntityManager($first->getConnection(), $first->getConfiguration(), $events));

            return $em;
        });
        $this->registry = $registry;

        $this->tenant = new Tenant('main', 'Main');
        $this->em->persist($this->tenant);
        $this->em->flush();
    }

    public function testInvalidUtf8InDnsRecordsIsStoredScrubbedAndTheScanGoesOn(): void
    {
        $poison = $this->dnsMonitor('Poison', 'poison.example');
        $next   = $this->dnsMonitor('Next', 'ok.example');
        $this->em->flush();

        $dns = new DnsCheckRunner(OfflineDns::guard(), false, static fn (string $host): array => $host === 'poison.example'
            ? [['host' => $host, 'type' => 'TXT', 'txt' => "v=\xff\xfe broken"]]
            : [['host' => $host, 'type' => 'TXT', 'txt' => 'v=ok']]);

        $runner = $this->runner($dns);

        self::assertSame(2, $runner->runDueChecks(new DateTimeImmutable('2026-01-01 12:00:00')));
        $this->em->clear();

        /** @var list<CheckResult> $results */
        $results = $this->em->getRepository(CheckResult::class)->findAll();
        self::assertCount(2, $results);
        foreach ($results as $result) {
            json_encode([$result->getMessage(), $result->getMetadata()], JSON_THROW_ON_ERROR);
        }
        self::assertNotNull($this->em->find(Monitor::class, $poison->getId())?->getNextCheckAt());
        self::assertNotNull($this->em->find(Monitor::class, $next->getId())?->getNextCheckAt());
    }

    public function testAFlushThatClosesTheManagerResetsItReschedulesTheMonitorAndTheScanGoesOn(): void
    {
        $poison = $this->dnsMonitor('Poison', 'poison.example');
        $next   = $this->dnsMonitor('Next', 'ok.example');
        $this->em->flush();

        // A failure inside UnitOfWork::commit() closes the entity manager (same effect as an unstorable value).
        $this->first->getEventManager()->addEventListener(Events::postPersist, new class {
            private bool $armed = true;

            public function postPersist(PostPersistEventArgs $args): void
            {
                $entity = $args->getObject();
                if ($this->armed && $entity instanceof CheckResult && $entity->getMonitor()->getName() === 'Poison') {
                    $this->armed = false;

                    throw new RuntimeException('cannot store this check');
                }
            }
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $logger->expects(self::once())->method('warning');

        $dns    = new DnsCheckRunner(OfflineDns::guard(), false, static fn (string $host): array => [['host' => $host, 'type' => 'A', 'ip' => '93.184.215.14']]);
        $runner = $this->runner($dns, $logger);

        self::assertSame(1, $runner->runDueChecks(new DateTimeImmutable('2026-01-01 12:00:00')));
        self::assertNotSame($this->first, $this->em->wrapped());
        self::assertTrue($this->em->isOpen());

        $this->em->clear();
        $stored = $this->em->find(Monitor::class, $poison->getId());
        self::assertInstanceOf(Monitor::class, $stored);
        self::assertEquals(new DateTimeImmutable('2026-01-01 12:01:00 UTC'), $stored->getNextCheckAt());
        /** @var list<CheckResult> $results */
        $results = $this->em->getRepository(CheckResult::class)->findAll();
        self::assertCount(1, $results);
        self::assertSame($next->getId(), $results[0]->getMonitor()->getId());
    }

    private function dnsMonitor(string $name, string $host): Monitor
    {
        $monitor = new Monitor($this->tenant, $name, MonitorType::Dns, $host);
        $monitor->setConfig(['hostname' => $host, 'record_type' => 'TXT'])->setIntervalSeconds(60)->setRetries(0);
        $monitor->setNextCheckAt(new DateTimeImmutable('2026-01-01 11:00:00 UTC'));
        $this->em->persist($monitor);

        return $monitor;
    }

    private function runner(DnsCheckRunner $dns, ?LoggerInterface $logger = null): DueChecksRunner
    {
        return new DueChecksRunner(
            new MonitorRepository($this->registry),
            $this->checkExecutorService([$dns], $this->em),
            true,
            new MockClock('2026-01-01 12:00:00 UTC'),
            0,
            $logger ?? $this->createStub(LoggerInterface::class),
            $this->registry,
        );
    }
}

/**
 * Entity manager whose wrapped instance can be replaced, like the lazy `doctrine.orm.entity_manager` service that
 * ManagerRegistry::resetManager() resets in place.
 */
final class SwappableEntityManager extends EntityManagerDecorator
{
    public function swap(EntityManagerInterface $entityManager): void
    {
        $this->wrapped = $entityManager;
    }

    public function wrapped(): EntityManagerInterface
    {
        return $this->wrapped;
    }
}
