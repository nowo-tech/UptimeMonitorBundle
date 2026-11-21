<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\BothHosts;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Parameter;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Query\CheckAggregateQueryInterface;
use Nowo\UptimeMonitorBundle\Query\IncidentQueryInterface;
use Nowo\UptimeMonitorBundle\Query\MonitorScope;
use Nowo\UptimeMonitorBundle\Service\TenantProvisionerInterface;
use Nowo\UptimeMonitorBundle\Tests\Support\TestSchemaTool;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function gc_collect_cycles;
use function memory_get_usage;

/**
 * 1.36 (REQ-RUNTIME-002, FrankenPHP worker mode with kernel reset false): the report read ports are stateless readonly
 * services of a kept-alive kernel. Another process changes the rollups and the incidents between requests; every read
 * sees the committed rows, nothing stays managed, memory stays bound.
 */
final class ReportPortsKernelTest extends KernelTestCase
{
    private EntityManagerInterface $application;

    protected static function getKernelClass(): string
    {
        return StandardHostTestKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $application = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $application);
        $this->application = $application;
        TestSchemaTool::of($application)->createSchema($application->getMetadataFactory()->getAllMetadata());
    }

    public function testTheKeptAliveKernelServesFreshReports(): void
    {
        $container   = self::getContainer();
        $provisioner = $container->get(AbstractHostTestKernel::TENANT_PORT);
        self::assertInstanceOf(TenantProvisionerInterface::class, $provisioner);
        $http = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $router = $container->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $bundle = $container->get(UptimeEntityManager::class);
        self::assertInstanceOf(UptimeEntityManager::class, $bundle);

        $tenant  = $provisioner->ensure('alpha', 'Alpha');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://api.test');
        $bucket  = new CheckAggregate($monitor, AggregatePeriod::Day, new DateTimeImmutable('2026-11-02 00:00:00'));
        $bucket->applyTotals(100, 100, 10);
        $incident = new Incident($monitor, CheckStatus::Down, null, new DateTimeImmutable('2026-11-03 00:00:00'));
        foreach ([$monitor, $bucket, $incident] as $entity) {
            $this->application->persist($entity);
        }
        $this->application->flush();
        $monitorId = (int) $monitor->getId();
        $path      = $router->generate('nowo_uptime_tenant_index');
        $scope     = MonitorScope::tenants(['alpha']);
        $from      = new DateTimeImmutable('2026-11-01 00:00:00');
        $to        = new DateTimeImmutable('2026-11-08 00:00:00');

        $aggregatePort = $container->get(AbstractHostTestKernel::AGGREGATE_PORT);
        $incidentPort  = $container->get(AbstractHostTestKernel::INCIDENT_PORT);
        self::assertInstanceOf(CheckAggregateQueryInterface::class, $aggregatePort);
        self::assertInstanceOf(IncidentQueryInterface::class, $incidentPort);
        foreach ([$aggregatePort, $incidentPort] as $port) {
            self::assertTrue((new ReflectionClass($port))->isReadOnly(), $port::class . ' keeps no state');
        }

        // A first request: its boundary drops what the setup's tenant provisioning left in the private manager.
        $this->serve($http, $path);
        $baseline = 0;
        for ($i = 0; $i < 300; ++$i) {
            // Another process rolls up and opens / resolves the incident between two requests.
            $this->application->createQuery('UPDATE ' . CheckAggregate::class . ' a SET a.checksUp = :up, a.checksDown = :down WHERE a.monitor = :monitor')
                ->setParameters(new ArrayCollection([new Parameter('up', 100 - $i % 100), new Parameter('down', $i % 100), new Parameter('monitor', $monitorId)]))
                ->execute();
            $this->application->createQuery('UPDATE ' . Incident::class . ' i SET i.endedAt = :ended WHERE i.monitor = :monitor')
                ->setParameters(new ArrayCollection([new Parameter('ended', $i % 2 === 0 ? null : new DateTimeImmutable('2026-11-03 00:00:10'), $i % 2 === 0 ? null : Types::DATETIME_IMMUTABLE), new Parameter('monitor', $monitorId)]))
                ->execute();

            self::assertSame($aggregatePort, $container->get(AbstractHostTestKernel::AGGREGATE_PORT));
            self::assertSame(100 - $i % 100, $aggregatePort->totalsByMonitor($scope, $from, $to)[$monitorId]->checksUp, 'request ' . $i . ' read fresh rollups');
            self::assertSame($i % 2 === 0 ? 1 : 0, $incidentPort->count($scope, $from, $to, open: true), 'request ' . $i . ' read fresh incidents');
            self::assertSame($i % 2 === 0 ? 0 : 10, $incidentPort->downtimeByMonitor($scope, $from, $to, new DateTimeImmutable('2026-11-03 00:00:00'))[$monitorId]->seconds);
            self::assertCount(1, $incidentPort->list($scope, $from, $to));
            self::assertSame(0, $bundle->getUnitOfWork()->size(), 'nothing is managed by a read');

            $this->serve($http, $path);
            self::assertFalse($bundle->isInitialized(), 'the boundary dropped the private manager');
            if ($i === 30) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(8 * 1024 * 1024, memory_get_usage() - $baseline, 'no per-request growth');
    }

    private function serve(HttpKernelInterface $http, string $path): void
    {
        $request  = Request::create($path);
        $response = $http->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        if ($http instanceof TerminableInterface) {
            $http->terminate($request, $response);
        }
    }
}
