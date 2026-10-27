<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Controller\MonitorController;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Form\MonitorFormType;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateChartService;
use Nowo\UptimeMonitorBundle\Service\DashboardViewBuilder;
use Nowo\UptimeMonitorBundle\Service\MonitorFactory;
use Nowo\UptimeMonitorBundle\Service\TenantDashboardSerializer;
use Nowo\UptimeMonitorBundle\Service\UptimeMetricsService;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\ControllerContainerTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Util\StringUtil;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

use function json_decode;

/**
 * Monitor create / edit submissions on a real SQLite schema (page and modal flows).
 */
#[CoversClass(MonitorController::class)]
final class MonitorControllerSubmitTest extends TestCase
{
    use ControllerContainerTrait;

    private EntityManagerInterface $em;
    private Tenant $tenant;
    private Monitor $group;
    private MonitorRepository $monitors;

    protected function setUp(): void
    {
        $this->em = SqliteEntityManagerFactory::create();
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $this->monitors = new MonitorRepository($registry);

        $this->tenant = new Tenant('main', 'Main');
        $this->group  = new Monitor($this->tenant, 'Payments', MonitorType::Group, 'Payments');
        $this->em->persist($this->tenant);
        $this->em->persist($this->group);
        $this->em->persist(new Monitor($this->tenant, 'Shop', MonitorType::Group, 'Shop'));
        $this->em->flush();
    }

    private function controller(): MonitorController
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $tenants = $this->createStub(TenantRepository::class);
        $tenants->method('findOneBySlug')->willReturnCallback(fn (string $slug): ?Tenant => $slug === 'main' ? $this->tenant : null);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $aggregates = new CheckAggregateRepository($registry);
        $results    = new CheckResultRepository($registry);

        return new MonitorController(
            $translator,
            $tenants,
            $this->monitors,
            $results,
            new MonitorFactory($this->monitors),
            new AggregateChartService($aggregates),
            new DashboardViewBuilder($this->monitors, $results, $aggregates, new UptimeMetricsService(), new TenantDashboardSerializer()),
            $this->em,
        );
    }

    /**
     * @param array<string, mixed> $override
     * @param array<string, string> $headers
     */
    private static function submit(string $path, array $override = [], array $headers = []): Request
    {
        $fields = $override + [
            'type'                        => 'https',
            'name'                        => 'API',
            'parentId'                    => '',
            'project'                     => '',
            'intervalSeconds'             => '60',
            'retries'                     => '0',
            'retryIntervalSeconds'        => '60',
            'requestTimeoutSeconds'       => '10',
            'resendNotificationAfterDown' => '0',
            'description'                 => '',
            'url'                         => 'https://api.example.test/health',
            'method'                      => 'GET',
            'expectedStatusCodes'         => '200-299',
            'maxRedirects'                => '10',
            'keyword'                     => '',
            'bodyEncoding'                => 'json',
            'httpBody'                    => '',
            'httpHeaders'                 => '',
            'proxy'                       => '',
            'authMethod'                  => 'none',
            'authUsername'                => '',
            'authPassword'                => '',
            'tags'                        => '',
            'host'                        => '',
            'port'                        => '443',
            'hostname'                    => '',
            'recordType'                  => 'A',
            'expectedDnsValue'            => '',
            'daysBeforeExpiry'            => '14',
            '_token'                      => 'valid',
        ];
        $request = Request::create($path, 'POST', [StringUtil::fqcnToBlockPrefix(MonitorFormType::class) => $fields]);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    public function testNewMonitorIsCreatedAndRedirects(): void
    {
        $request    = self::submit('/main/monitors/new', ['parentId' => (string) $this->group->getId()]);
        $controller = $this->controller();
        $this->bindController($controller, true, $request);

        $response = $controller->new('main', $request);

        self::assertTrue($response->isRedirect());
        $created = $this->monitors->findOneBy(['name' => 'API']);
        self::assertInstanceOf(Monitor::class, $created);
        self::assertSame($this->group, $created->getParent());
        $session = $request->getSession();
        self::assertInstanceOf(Session::class, $session);
        self::assertSame(['flash.monitor.created'], $session->getFlashBag()->peek('success'));
    }

    public function testModalCreateAnswersJsonWithTheReturnTarget(): void
    {
        $request    = self::submit('/main/monitors/new', [], ['X-Uptime-Modal' => '1', 'X-Uptime-Return' => 'show']);
        $controller = $this->controller();
        $this->bindController($controller, true, $request);

        $response = $controller->new('main', $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(['ok' => true, 'message' => 'flash.monitor.created', 'return' => 'show'], json_decode((string) $response->getContent(), true));
    }

    public function testModalSubmitWithErrorsIsUnprocessable(): void
    {
        $request    = self::submit('/main/monitors/new', ['name' => ''], ['X-Uptime-Modal' => '1']);
        $controller = $this->controller();
        $this->bindController($controller, true, $request);

        self::assertSame(422, $controller->new('main', $request)->getStatusCode());
    }

    public function testEditSavesThePageAndTheModalFlows(): void
    {
        $monitor = new Monitor($this->tenant, 'Old', MonitorType::Https, 'https://old.test');
        $this->em->persist($monitor);
        $this->em->flush();
        $id         = (int) $monitor->getId();
        $controller = $this->controller();

        $page = self::submit('/main/monitors/' . $id . '/edit', ['name' => 'Renamed']);
        $this->bindController($controller, true, $page);
        self::assertTrue($controller->edit('main', $id, $page)->isRedirect());
        self::assertSame('Renamed', $monitor->getName());

        $modal = self::submit('/main/monitors/' . $id . '/edit', ['name' => 'Again'], ['X-Uptime-Modal' => '1', 'X-Uptime-Return' => 'elsewhere']);
        $this->bindController($controller, true, $modal);
        $response = $controller->edit('main', $id, $modal);
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame('dashboard', json_decode((string) $response->getContent(), true)['return'] ?? null);

        // A group cannot be its own parent: the edit form of a group lists the other groups only.
        $groupForm = Request::create('/main/monitors/' . $this->group->getId() . '/edit');
        $this->bindController($controller, true, $groupForm);
        self::assertSame('rendered', $controller->edit('main', (int) $this->group->getId(), $groupForm)->getContent());
    }

    public function testNewOnUnknownTenantIsNotFound(): void
    {
        $controller = $this->controller();
        $this->bindController($controller, true, new Request());

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Tenant "nope" not found.');
        $controller->new('nope', new Request());
    }
}
