<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\UptimeMonitorBundle\Controller\MonitorController;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorQuotaCheckEvent;
use Nowo\UptimeMonitorBundle\Event\UptimeEvents;
use Nowo\UptimeMonitorBundle\Form\MonitorFormType;
use Nowo\UptimeMonitorBundle\Limits\ConfigurableTenantLimits;
use Nowo\UptimeMonitorBundle\Limits\TenantQuota;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Security\MonitorAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\MonitorReadScope;
use Nowo\UptimeMonitorBundle\Service\AggregateChartService;
use Nowo\UptimeMonitorBundle\Service\DashboardViewBuilder;
use Nowo\UptimeMonitorBundle\Service\MonitorFactory;
use Nowo\UptimeMonitorBundle\Service\TenantDashboardSerializer;
use Nowo\UptimeMonitorBundle\Service\UptimeMetricsService;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\ControllerContainerTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\Util\StringUtil;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function in_array;
use function json_decode;
use function json_encode;

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

    private function controller(?EventDispatcher $dispatcher = null, ?TenantQuota $quota = null, ?MonitorReadScope $readScope = null): MonitorController
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
            quota: $quota,
            eventDispatcher: $dispatcher,
            readScope: $readScope,
        );
    }

    private function denying(string $action, string $reason): EventDispatcher
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(UptimeEvents::MONITOR_QUOTA_CHECK, static function (MonitorQuotaCheckEvent $check) use ($action, $reason): void {
            if ($check->action === $action) {
                $check->deny($reason);
            }
        });

        return $dispatcher;
    }

    public function testAQuotaDenialRefusesTheNewMonitorWithItsReason(): void
    {
        $seen       = [];
        $dispatcher = $this->denying(MonitorQuotaCheckEvent::ACTION_CREATE, 'Your plan allows 3 monitors.');
        $dispatcher->addListener(MonitorQuotaCheckEvent::class, static function (MonitorQuotaCheckEvent $check) use (&$seen): void {
            $seen[] = [$check->tenantSlug, $check->type, $check->monitor];
        }, 10);
        $request    = $this->submit('/main/monitors/new', ['name' => 'Server'], ['X-Uptime-Modal' => '1']);
        $controller = $this->controller($dispatcher);
        $this->bindController($controller, true, $request);

        $response = $controller->new('main', $request);

        self::assertSame(422, $response->getStatusCode());
        self::assertNull($this->monitors->findOneBy(['name' => 'Server']), 'nothing written');
        self::assertSame([['main', MonitorType::Https, null]], $seen);
        $form = $this->renderedContext['form'] ?? null;
        self::assertInstanceOf(FormView::class, $form);
        $errors = $form->vars['errors'];
        self::assertInstanceOf(FormErrorIterator::class, $errors);
        $error = $errors[0];
        self::assertInstanceOf(FormError::class, $error);
        self::assertSame('Your plan allows 3 monitors.', $error->getMessage());
    }

    public function testAnAllowedQuotaCheckCreatesTheMonitor(): void
    {
        $request    = $this->submit('/main/monitors/new');
        $controller = $this->controller($this->denying(MonitorQuotaCheckEvent::ACTION_RESUME, 'no'));
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->new('main', $request)->isRedirect());
        self::assertInstanceOf(Monitor::class, $this->monitors->findOneBy(['name' => 'API']));
    }

    public function testAQuotaDenialKeepsAMonitorPausedAndSaysWhy(): void
    {
        $monitor = new Monitor($this->tenant, 'Paused', MonitorType::Https, 'https://p.test');
        $monitor->setPaused(true);
        $this->em->persist($monitor);
        $this->em->flush();
        $id         = (int) $monitor->getId();
        $controller = $this->controller($this->denying(MonitorQuotaCheckEvent::ACTION_RESUME, 'Monitor limit reached.'));

        $request = Request::create('/main/monitors/' . $id . '/toggle-pause', 'POST', ['_token' => 'valid']);
        $this->bindController($controller, true, $request);
        $controller->togglePause('main', $id, $request);

        self::assertTrue($monitor->isPaused());
        $session = $request->getSession();
        self::assertInstanceOf(Session::class, $session);
        self::assertSame(['Monitor limit reached.'], $session->getFlashBag()->peek('error'));

        // Pausing is never checked.
        $monitor->setPaused(false);
        $this->em->flush();
        $pause = Request::create('/main/monitors/' . $id . '/toggle-pause', 'POST', ['_token' => 'valid']);
        $this->bindController($controller, true, $pause);
        $controller->togglePause('main', $id, $pause);
        self::assertTrue($monitor->isPaused());
    }

    private function persisted(string $name, MonitorType $type, bool $paused = false): Monitor
    {
        $monitor = new Monitor($this->tenant, $name, $type, 'https://p.test');
        $monitor->setPaused($paused);
        $this->em->persist($monitor);
        $this->em->flush();

        return $monitor;
    }

    /**
     * Posts the edit form of a monitor with a listener denying `$action`; returns the checks it saw.
     *
     * @param array<string, mixed> $fields
     *
     * @return list<MonitorQuotaCheckEvent>
     */
    private function editDenying(Monitor $monitor, string $action, array $fields): array
    {
        $seen       = [];
        $dispatcher = $this->denying($action, 'Plan limit.');
        $dispatcher->addListener(MonitorQuotaCheckEvent::class, static function (MonitorQuotaCheckEvent $check) use (&$seen): void {
            $seen[] = $check;
        }, 10);
        $id         = (int) $monitor->getId();
        $request    = $this->submit('/main/monitors/' . $id . '/edit', $fields);
        $controller = $this->controller($dispatcher);
        $this->bindController($controller, true, $request);

        self::assertFalse($controller->edit('main', $id, $request)->isRedirect(), 'the form comes back');
        $form = $this->renderedContext['form'] ?? null;
        self::assertInstanceOf(FormView::class, $form);
        $errors = $form->vars['errors'];
        self::assertInstanceOf(FormErrorIterator::class, $errors);
        $error = $errors[0];
        self::assertInstanceOf(FormError::class, $error);
        self::assertSame('Plan limit.', $error->getMessage());

        return $seen;
    }

    public function testGuM1UncheckingPausedInTheEditFormIsAResume(): void
    {
        $monitor = $this->persisted('Paused', MonitorType::Https, true);

        $seen = $this->editDenying($monitor, MonitorQuotaCheckEvent::ACTION_RESUME, ['name' => 'Renamed']);

        $this->em->refresh($monitor);
        self::assertTrue($monitor->isPaused(), 'still paused');
        self::assertSame('Paused', $monitor->getName(), 'nothing of the edit written');
        self::assertCount(1, $seen);
        self::assertSame([MonitorQuotaCheckEvent::ACTION_RESUME, 'main', MonitorType::Https, $monitor, null], [$seen[0]->action, $seen[0]->tenantSlug, $seen[0]->type, $seen[0]->monitor, $seen[0]->previousType]);
    }

    public function testGuM1AGroupTurnedIntoACheckIsACreate(): void
    {
        $seen = $this->editDenying($this->group, MonitorQuotaCheckEvent::ACTION_CREATE, ['name' => 'Payments']);

        $this->em->refresh($this->group);
        self::assertTrue($this->group->isGroup(), 'still a group');
        self::assertCount(1, $seen);
        self::assertSame([MonitorQuotaCheckEvent::ACTION_CREATE, 'main', MonitorType::Https, $this->group, MonitorType::Group], [$seen[0]->action, $seen[0]->tenantSlug, $seen[0]->type, $seen[0]->monitor, $seen[0]->previousType]);
    }

    public function testGuM1ATypeChangeToPushIsACreate(): void
    {
        $monitor = $this->persisted('Web', MonitorType::Https);

        $seen = $this->editDenying($monitor, MonitorQuotaCheckEvent::ACTION_CREATE, ['name' => 'Web', 'type' => 'push']);

        $this->em->refresh($monitor);
        self::assertSame(MonitorType::Https, $monitor->getType(), 'still https');
        self::assertNull($monitor->getPushKey());
        self::assertCount(1, $seen);
        self::assertSame([MonitorQuotaCheckEvent::ACTION_CREATE, 'main', MonitorType::Push, $monitor, MonitorType::Https], [$seen[0]->action, $seen[0]->tenantSlug, $seen[0]->type, $seen[0]->monitor, $seen[0]->previousType]);
    }

    public function testGuM1AnEditChangingBothChecksBothCreateFirst(): void
    {
        $monitor = $this->persisted('Both', MonitorType::Https, true);

        $seen = $this->editDenying($monitor, MonitorQuotaCheckEvent::ACTION_RESUME, ['name' => 'Both', 'type' => 'push']);

        $this->em->refresh($monitor);
        self::assertSame([MonitorType::Https, true], [$monitor->getType(), $monitor->isPaused()]);
        self::assertSame([MonitorQuotaCheckEvent::ACTION_CREATE, MonitorQuotaCheckEvent::ACTION_RESUME], array_map(static fn (MonitorQuotaCheckEvent $check): string => $check->action, $seen));
        self::assertSame(MonitorType::Push, $seen[1]->type, 'the type it will have');
    }

    public function testGuM1AnEditChangingNeitherDispatchesNothing(): void
    {
        $monitor    = $this->persisted('Quiet', MonitorType::Https);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MonitorQuotaCheckEvent::class, static function (): never {
            self::fail('no check expected');
        });
        $id         = (int) $monitor->getId();
        $request    = $this->submit('/main/monitors/' . $id . '/edit', ['name' => 'Quiet']);
        $controller = $this->controller($dispatcher);
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->edit('main', $id, $request)->isRedirect());
    }

    public function testGuM1AnAllowedResumeByEditIsSaved(): void
    {
        $monitor    = $this->persisted('Quiet', MonitorType::Https, true);
        $id         = (int) $monitor->getId();
        $request    = $this->submit('/main/monitors/' . $id . '/edit', ['name' => 'Quiet']);
        $controller = $this->controller($this->denying(MonitorQuotaCheckEvent::ACTION_CREATE, 'no'));
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->edit('main', $id, $request)->isRedirect());
        self::assertFalse($monitor->isPaused());
    }

    public function testGuM1APerTypeListenerCanRefuseResumingAPausedPushMonitorOnly(): void
    {
        $push       = $this->persisted('Agent', MonitorType::Push, true);
        $https      = $this->persisted('Site', MonitorType::Https, true);
        $seen       = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MonitorQuotaCheckEvent::class, static function (MonitorQuotaCheckEvent $check) use (&$seen): void {
            $seen[] = [$check->action, $check->tenantSlug, $check->type, $check->monitor, $check->previousType];
            if ($check->action === MonitorQuotaCheckEvent::ACTION_RESUME && $check->type === MonitorType::Push) {
                $check->deny('Push limit reached.');
            }
        });
        $controller = $this->controller($dispatcher);

        foreach ([$push, $https] as $monitor) {
            $id      = (int) $monitor->getId();
            $request = Request::create('/main/monitors/' . $id . '/toggle-pause', 'POST', ['_token' => 'valid']);
            $this->bindController($controller, true, $request);
            $controller->togglePause('main', $id, $request);
        }

        self::assertTrue($push->isPaused(), 'push refused');
        self::assertFalse($https->isPaused(), 'https allowed');
        self::assertSame([
            [MonitorQuotaCheckEvent::ACTION_RESUME, 'main', MonitorType::Push, $push, null],
            [MonitorQuotaCheckEvent::ACTION_RESUME, 'main', MonitorType::Https, $https, null],
        ], $seen);
    }

    public function testGuL1TheChecksRunInsideTheTenantRowLock(): void
    {
        $paused     = $this->persisted('Locked', MonitorType::Https, true);
        $edited     = $this->persisted('Edited', MonitorType::Https, true);
        $inLock     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MonitorQuotaCheckEvent::class, function (MonitorQuotaCheckEvent $check) use (&$inLock): void {
            $inLock[] = $this->em->getConnection()->isTransactionActive();
        });
        $controller = $this->controller($dispatcher, new TenantQuota(new ConfigurableTenantLimits(0, 30), $this->monitors));

        $id      = (int) $paused->getId();
        $request = Request::create('/main/monitors/' . $id . '/toggle-pause', 'POST', ['_token' => 'valid']);
        $this->bindController($controller, true, $request);
        $controller->togglePause('main', $id, $request);
        self::assertFalse($paused->isPaused());

        $id      = (int) $edited->getId();
        $request = $this->submit('/main/monitors/' . $id . '/edit', ['name' => 'Edited']);
        $this->bindController($controller, true, $request);
        self::assertTrue($controller->edit('main', $id, $request)->isRedirect());

        $request = $this->submit('/main/monitors/new', ['name' => 'New group', 'type' => 'group']);
        $this->bindController($controller, true, $request);
        self::assertTrue($controller->new('main', $request)->isRedirect());

        self::assertSame([true, true, true], $inLock, 'resume, edit and group create');
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    public function testGuL1PausingTakesNoLock(): void
    {
        $monitor = $this->persisted('Running', MonitorType::Https);
        $em      = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('wrapInTransaction');
        $em->expects(self::once())->method('flush');
        $this->em = $em;
        $id       = 4;
        $repo     = $this->createStub(MonitorRepository::class);
        $repo->method('find')->willReturn($monitor);
        $this->monitors = $repo;
        $controller     = $this->controller(null, new TenantQuota(new ConfigurableTenantLimits(0, 30), $repo));

        $request = Request::create('/main/monitors/' . $id . '/toggle-pause', 'POST', ['_token' => 'valid']);
        $this->bindController($controller, true, $request);
        $controller->togglePause('main', $id, $request);

        self::assertTrue($monitor->isPaused());
    }

    /**
     * A checker sharing only the listed monitor names, and the tenant with nobody (`$tenantAccess` false).
     *
     * @param list<string> $shared
     */
    private function readScope(array $shared, bool $tenantAccess): MonitorReadScope
    {
        $checker = $this->createStub(MonitorAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->method('canAccessTenant')->willReturn($tenantAccess);
        $checker->method('canViewMonitor')->willReturnCallback(static fn (object $user, Monitor $monitor): bool => $tenantAccess || in_array($monitor->getName(), $shared, true));

        return new MonitorReadScope($checker);
    }

    /**
     * @return array<string, mixed> The context of the page
     */
    private function showAs(Monitor $monitor, MonitorReadScope $scope): array
    {
        $controller = $this->controller(null, null, $scope);
        $this->bindController($controller, true, new Request());
        $controller->show('main', (int) $monitor->getId());

        return $this->renderedContext;
    }

    public function testGuL2AGroupPageListsOnlyTheChildrenTheUserMayView(): void
    {
        foreach (['shared-child', 'hidden-child'] as $name) {
            $this->persisted($name, MonitorType::Https)->setParent($this->group);
        }
        $this->em->flush();

        $shared = $this->showAs($this->group, $this->readScope(['shared-child'], false));
        self::assertIsArray($shared['detail']);
        self::assertSame(['shared-child'], array_map(static fn (array $child): string => $child['monitor']->getName(), $shared['detail']['children']));

        $tenant = $this->showAs($this->group, $this->readScope([], true));
        self::assertIsArray($tenant['detail']);
        self::assertCount(2, $tenant['detail']['children']);
    }

    public function testGuL2TheParentGroupIsShownToTenantUsersOnly(): void
    {
        $child = $this->persisted('child', MonitorType::Https)->setParent($this->group);
        $this->em->flush();

        $labels = static function (array $context): array {
            self::assertInstanceOf(ShowPage::class, $context['page']);

            return array_map(static fn (DetailItem $item): mixed => $item->value, $context['page']->sections[0]->items);
        };

        self::assertNotContains('Payments', $labels($this->showAs($child, $this->readScope(['child'], false))));
        self::assertContains('Payments', $labels($this->showAs($child, $this->readScope([], true))));
    }

    public function testGuI6SlotTemplatesGetAMaskedViewOfTheMonitor(): void
    {
        $monitor = new Monitor($this->tenant, 'Secret', MonitorType::Https, 'https://user:placeholder@p.test/');
        $monitor->setConfig(['url' => 'https://user:placeholder@p.test/', 'headers' => ['Authorization' => 'placeholder']]);
        $this->em->persist($monitor);
        $this->em->flush();

        $context = $this->showAs($monitor, $this->readScope([], true));

        self::assertIsArray($context['monitor_safe']);
        self::assertStringNotContainsString('placeholder', (string) json_encode($context['monitor_safe']));
        self::assertSame($monitor, $context['monitor'], 'the raw entity stays for existing overrides');
    }

    /**
     * @param array<string, mixed> $override
     * @param array<string, string> $headers
     */
    private function submit(string $path, array $override = [], array $headers = []): Request
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
        $request    = $this->submit('/main/monitors/new', ['parentId' => (string) $this->group->getId()]);
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
        $request    = $this->submit('/main/monitors/new', [], ['X-Uptime-Modal' => '1', 'X-Uptime-Return' => 'show']);
        $controller = $this->controller();
        $this->bindController($controller, true, $request);

        $response = $controller->new('main', $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(['ok' => true, 'message' => 'flash.monitor.created', 'return' => 'show'], json_decode((string) $response->getContent(), true));
    }

    public function testModalSubmitWithErrorsIsUnprocessable(): void
    {
        $request    = $this->submit('/main/monitors/new', ['name' => ''], ['X-Uptime-Modal' => '1']);
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

        $page = $this->submit('/main/monitors/' . $id . '/edit', ['name' => 'Renamed']);
        $this->bindController($controller, true, $page);
        self::assertTrue($controller->edit('main', $id, $page)->isRedirect());
        self::assertSame('Renamed', $monitor->getName());

        $modal = $this->submit('/main/monitors/' . $id . '/edit', ['name' => 'Again'], ['X-Uptime-Modal' => '1', 'X-Uptime-Return' => 'elsewhere']);
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
