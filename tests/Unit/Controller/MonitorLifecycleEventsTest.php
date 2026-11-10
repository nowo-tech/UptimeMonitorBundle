<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Controller\MonitorController;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorCreatedEvent;
use Nowo\UptimeMonitorBundle\Event\MonitorDeletedEvent;
use Nowo\UptimeMonitorBundle\Event\MonitorPausedEvent;
use Nowo\UptimeMonitorBundle\Event\MonitorQuotaCheckEvent;
use Nowo\UptimeMonitorBundle\Event\MonitorUpdatedEvent;
use Nowo\UptimeMonitorBundle\Event\UptimeEvents;
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
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\Util\StringUtil;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Contracts\Translation\TranslatorInterface;

use function json_encode;

/**
 * Since 1.25: admin UI create, edit, delete and pause dispatch id-only lifecycle events after the commit.
 */
#[CoversClass(MonitorController::class)]
#[CoversClass(MonitorCreatedEvent::class)]
#[CoversClass(MonitorUpdatedEvent::class)]
#[CoversClass(MonitorDeletedEvent::class)]
#[CoversClass(MonitorPausedEvent::class)]
final class MonitorLifecycleEventsTest extends TestCase
{
    use ControllerContainerTrait;

    private EntityManagerInterface $em;
    private Tenant $tenant;
    private MonitorRepository $monitors;
    /** @var list<object> */
    private array $events = [];
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->em = SqliteEntityManagerFactory::create();
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $this->monitors = new MonitorRepository($registry);
        $this->tenant   = new Tenant('main', 'Main');
        $this->em->persist($this->tenant);
        $this->em->flush();

        $this->dispatcher = new EventDispatcher();
        foreach ([UptimeEvents::MONITOR_CREATED, UptimeEvents::MONITOR_UPDATED, UptimeEvents::MONITOR_DELETED, UptimeEvents::MONITOR_PAUSED] as $name) {
            $this->dispatcher->addListener($name, function (object $event): void {
                // After the commit: the write is visible from a fresh query.
                $this->events[] = $event;
            });
        }
    }

    private function controller(bool $withUser = true): MonitorController
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $tenants = $this->createStub(TenantRepository::class);
        $tenants->method('findOneBySlug')->willReturnCallback(fn (string $slug): ?Tenant => $slug === 'main' ? $this->tenant : null);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $aggregates = new CheckAggregateRepository($registry);
        $results    = new CheckResultRepository($registry);
        $storage    = null;
        if ($withUser) {
            $storage = new TokenStorage();
            $storage->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null), 'main'));
        }

        return new MonitorController(
            $translator,
            $tenants,
            $this->monitors,
            $results,
            new MonitorFactory($this->monitors),
            new AggregateChartService($aggregates),
            new DashboardViewBuilder($this->monitors, $results, $aggregates, new UptimeMetricsService(), new TenantDashboardSerializer()),
            $this->em,
            eventDispatcher: $this->dispatcher,
            tokenStorage: $storage,
            clock: new MockClock('2026-11-10 10:00:00'),
        );
    }

    public function testCreateDispatchesMonitorCreatedWithTheNewId(): void
    {
        $request    = $this->submit('/main/monitors/new');
        $controller = $this->controller();
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->new('main', $request)->isRedirect());

        $created = $this->monitors->findOneBy(['name' => 'API']);
        self::assertInstanceOf(Monitor::class, $created);
        self::assertCount(1, $this->events);
        $event = $this->events[0];
        self::assertInstanceOf(MonitorCreatedEvent::class, $event);
        self::assertSame('main', $event->tenantSlug);
        self::assertSame($created->getId(), $event->monitorId);
        self::assertSame('alice', $event->actorId);
        self::assertEquals(new DateTimeImmutable('2026-11-10 10:00:00'), $event->occurredAt);
    }

    public function testARefusedCreateDispatchesNothing(): void
    {
        $this->dispatcher->addListener(MonitorQuotaCheckEvent::class, static function (MonitorQuotaCheckEvent $check): void {
            $check->deny('full');
        });
        $request    = $this->submit('/main/monitors/new');
        $controller = $this->controller();
        $this->bindController($controller, true, $request);

        $controller->new('main', $request);

        self::assertSame([], $this->events);
    }

    public function testEditDispatchesTheChangedFieldNamesOnly(): void
    {
        $monitor    = $this->persisted('Old', true);
        $id         = (int) $monitor->getId();
        $request    = $this->submit('/main/monitors/' . $id . '/edit', ['name' => 'New', 'authPassword' => 'placeholder']);
        $controller = $this->controller(false);
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->edit('main', $id, $request)->isRedirect());

        self::assertCount(1, $this->events);
        $event = $this->events[0];
        self::assertInstanceOf(MonitorUpdatedEvent::class, $event);
        self::assertSame($id, $event->monitorId);
        self::assertContains('name', $event->changedFields);
        self::assertContains('paused', $event->changedFields, 'the unchecked box resumed it');
        self::assertNotContains('method', $event->changedFields);
        self::assertNull($event->actorId, 'no security token');
        self::assertStringNotContainsString('placeholder', (string) json_encode($event));
    }

    /** Since 1.25: the edit form never renders the stored password; a replaced password is reported by name. */
    public function testTheStoredPasswordIsNotRenderedAndAChangeIsReportedByName(): void
    {
        $monitor = $this->persisted('Basic');
        $monitor->setConfig(['url' => 'https://p.test', 'method' => 'GET', 'auth_method' => 'basic', 'auth_username' => 'user', 'auth_password' => 'stored-value']);
        $this->em->flush();
        $id         = (int) $monitor->getId();
        $controller = $this->controller();
        $get        = Request::create('/main/monitors/' . $id . '/edit');
        $this->bindController($controller, true, $get);

        $controller->edit('main', $id, $get);
        $form = $this->renderedContext['form'] ?? null;
        self::assertInstanceOf(FormView::class, $form);
        self::assertSame('', $form['authPassword']->vars['value']);
        self::assertStringNotContainsString('stored-value', (string) json_encode($form['authPassword']->vars['value']));

        $post = $this->submit('/main/monitors/' . $id . '/edit', ['name' => 'Basic', 'url' => 'https://p.test', 'authMethod' => 'basic', 'authUsername' => 'user', 'authPassword' => 'new-value']);
        $this->bindController($controller, true, $post);
        $controller->edit('main', $id, $post);

        self::assertSame('new-value', $monitor->getConfig()['auth_password']);
        $event = $this->events[0] ?? null;
        self::assertInstanceOf(MonitorUpdatedEvent::class, $event);
        self::assertContains('authPassword', $event->changedFields);
        self::assertStringNotContainsString('new-value', (string) json_encode($event));
    }

    public function testDeleteDispatchesTheIdOfTheRemovedMonitor(): void
    {
        $monitor    = $this->persisted('Doomed');
        $id         = (int) $monitor->getId();
        $request    = Request::create('/main/monitors/' . $id . '/delete', 'POST', ['_token' => 'valid']);
        $controller = $this->controller();
        $this->bindController($controller, true, $request);

        $controller->delete('main', $id, $request);

        self::assertNull($this->monitors->find($id));
        self::assertCount(1, $this->events);
        self::assertInstanceOf(MonitorDeletedEvent::class, $this->events[0]);
        self::assertSame($id, $this->events[0]->monitorId);
        self::assertSame('alice', $this->events[0]->actorId);
    }

    public function testInvalidCsrfDispatchesNothing(): void
    {
        $monitor    = $this->persisted('Kept');
        $id         = (int) $monitor->getId();
        $controller = $this->controller();
        $request    = Request::create('/main/monitors/' . $id . '/delete', 'POST', ['_token' => 'bad']);
        $this->bindController($controller, false, $request);

        $controller->delete('main', $id, $request);
        $controller->togglePause('main', $id, $request);

        self::assertSame([], $this->events);
    }

    public function testPauseThenResumeDispatchesTheNewState(): void
    {
        $monitor    = $this->persisted('Toggled');
        $id         = (int) $monitor->getId();
        $request    = Request::create('/main/monitors/' . $id . '/toggle-pause', 'POST', ['_token' => 'valid']);
        $controller = $this->controller();
        $this->bindController($controller, true, $request);

        $controller->togglePause('main', $id, $request);
        $controller->togglePause('main', $id, $request);

        self::assertCount(2, $this->events);
        self::assertInstanceOf(MonitorPausedEvent::class, $this->events[0]);
        self::assertTrue($this->events[0]->paused);
        self::assertInstanceOf(MonitorPausedEvent::class, $this->events[1]);
        self::assertFalse($this->events[1]->paused);
        self::assertSame($id, $this->events[1]->monitorId);
    }

    public function testADeniedResumeDispatchesNoPausedEvent(): void
    {
        $this->dispatcher->addListener(MonitorQuotaCheckEvent::class, static function (MonitorQuotaCheckEvent $check): void {
            $check->deny('full');
        });
        $monitor    = $this->persisted('Paused', true);
        $id         = (int) $monitor->getId();
        $request    = Request::create('/main/monitors/' . $id . '/toggle-pause', 'POST', ['_token' => 'valid']);
        $controller = $this->controller();
        $this->bindController($controller, true, $request);

        $controller->togglePause('main', $id, $request);

        self::assertSame([], $this->events);
    }

    private function persisted(string $name, bool $paused = false): Monitor
    {
        $monitor = new Monitor($this->tenant, $name, MonitorType::Https, 'https://p.test');
        $monitor->setConfig(['url' => 'https://p.test', 'method' => 'GET']);
        $monitor->setPaused($paused);
        $this->em->persist($monitor);
        $this->em->flush();

        return $monitor;
    }

    /**
     * @param array<string, string> $override
     */
    private function submit(string $path, array $override = []): Request
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

        return Request::create($path, 'POST', [StringUtil::fqcnToBlockPrefix(MonitorFormType::class) => $fields]);
    }
}
