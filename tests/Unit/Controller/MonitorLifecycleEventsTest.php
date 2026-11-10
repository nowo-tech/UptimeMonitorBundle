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

use function array_intersect;
use function array_values;
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

    /**
     * Since 1.25.1 (KE-U-I5): the edit form (page, dialog, re-render after an error) never renders the stored URL /
     * proxy userinfo, token values or header values; unchanged masks keep them, and a change is reported by name.
     */
    public function testUrlProxyAndHeaderSecretsAreWriteOnlyOnEveryEditPath(): void
    {
        $monitor = $this->persisted('Secrets');
        $monitor->setConfig([
            'url'     => 'https://url-user:url-pass@p.test/h?token=url-token',
            'method'  => 'GET',
            'proxy'   => 'http://proxy-user:proxy-pass@proxy.test:3128',
            'headers' => ['Authorization' => 'Bearer header-secret', 'X-Api-Key' => 'key-secret'],
        ]);
        $this->em->flush();
        $id         = (int) $monitor->getId();
        $controller = $this->controller();
        $secrets    = ['url-user', 'url-pass', 'url-token', 'proxy-user', 'proxy-pass', 'header-secret', 'key-secret'];
        $masked     = [
            'url'         => 'https://***@p.test/h?token=***',
            'proxy'       => 'http://***@proxy.test:3128',
            'httpHeaders' => "Authorization: ***\nX-Api-Key: ***",
        ];

        // The page and the dialog.
        foreach ([[], ['X-Uptime-Modal' => '1']] as $headers) {
            $get = Request::create('/main/monitors/' . $id . '/edit');
            $get->headers->add($headers);
            $this->bindController($controller, true, $get);
            $controller->edit('main', $id, $get);
            $this->assertRenderedMasks($masked, $secrets);
        }

        // A validation error re-renders the submitted masks, not the stored values.
        $invalid = $this->submit('/main/monitors/' . $id . '/edit', ['name' => ''] + $masked, ['X-Uptime-Modal' => '1']);
        $this->bindController($controller, true, $invalid);
        self::assertSame(422, $controller->edit('main', $id, $invalid)->getStatusCode());
        $this->assertRenderedMasks($masked, $secrets);
        self::assertSame([], $this->events);

        // Saved with the masks: every secret is kept, nothing is reported.
        $keep = $this->submit('/main/monitors/' . $id . '/edit', ['name' => 'Secrets'] + $masked, ['X-Uptime-Modal' => '1']);
        $this->bindController($controller, true, $keep);
        $response = $controller->edit('main', $id, $keep);
        self::assertStringNotContainsString('secret', (string) $response->getContent());
        self::assertSame('https://url-user:url-pass@p.test/h?token=url-token', $monitor->getConfig()['url']);
        self::assertSame('http://proxy-user:proxy-pass@proxy.test:3128', $monitor->getConfig()['proxy']);
        self::assertSame(['Authorization' => 'Bearer header-secret', 'X-Api-Key' => 'key-secret'], $monitor->getConfig()['headers']);
        $event = $this->events[0] ?? null;
        self::assertInstanceOf(MonitorUpdatedEvent::class, $event);
        self::assertSame([], array_values(array_intersect(['url', 'proxy', 'httpHeaders'], $event->changedFields)));

        // Typed values replace them; the change is reported by name only.
        $this->events = [];
        $replace      = $this->submit('/main/monitors/' . $id . '/edit', [
            'name'        => 'Secrets',
            'url'         => 'https://***@p.test/h?token=new-token',
            'proxy'       => 'http://new-proxy@proxy.test:3128',
            'httpHeaders' => "Authorization: ***\nX-Api-Key: new-key",
        ]);
        $this->bindController($controller, true, $replace);
        $controller->edit('main', $id, $replace);
        self::assertSame('https://url-user:url-pass@p.test/h?token=new-token', $monitor->getConfig()['url']);
        self::assertSame('http://new-proxy@proxy.test:3128', $monitor->getConfig()['proxy']);
        self::assertSame(['Authorization' => 'Bearer header-secret', 'X-Api-Key' => 'new-key'], $monitor->getConfig()['headers']);
        $event = $this->events[0] ?? null;
        self::assertInstanceOf(MonitorUpdatedEvent::class, $event);
        foreach (['url', 'proxy', 'httpHeaders'] as $field) {
            self::assertContains($field, $event->changedFields);
        }
        $json = (string) json_encode($event);
        foreach (['new-token', 'new-proxy', 'new-key', 'header-secret', 'url-pass'] as $value) {
            self::assertStringNotContainsString($value, $json);
        }
    }

    /**
     * Since 1.25.1: masks submitted with another host (or proxy host) are refused on their fields; nothing is saved.
     */
    public function testMasksForAnotherDestinationAskForTheSecretsAgainAndSaveNothing(): void
    {
        $monitor = $this->persisted('Moved');
        $config  = [
            'url'           => 'https://u:pw@p.test/h',
            'method'        => 'GET',
            'proxy'         => 'http://pu:pp@proxy.test:3128',
            'headers'       => ['Authorization' => 'Bearer abc'],
            'auth_method'   => 'basic',
            'auth_username' => 'user',
            'auth_password' => 'stored',
        ];
        $monitor->setConfig($config);
        $this->em->flush();
        $id         = (int) $monitor->getId();
        $controller = $this->controller();

        $post = $this->submit('/main/monitors/' . $id . '/edit', [
            'name'         => 'Moved',
            'url'          => 'https://***@evil.test/h',
            'proxy'        => 'http://***@evil-proxy.test:3128',
            'httpHeaders'  => 'Authorization: ***',
            'authMethod'   => 'basic',
            'authUsername' => 'user',
        ], ['X-Uptime-Modal' => '1']);
        $this->bindController($controller, true, $post);

        self::assertSame(422, $controller->edit('main', $id, $post)->getStatusCode());
        $form = $this->renderedContext['form'] ?? null;
        self::assertInstanceOf(FormView::class, $form);
        foreach (['url', 'proxy', 'httpHeaders', 'authPassword'] as $field) {
            self::assertCount(1, $form[$field]->vars['errors'], $field);
            self::assertSame('form.monitor.secret_retype', $form[$field]->vars['errors'][0]->getMessage());
        }
        self::assertSame(json_encode($config), json_encode($monitor->getConfig()), 'nothing saved');
        self::assertSame([], $this->events);

        // Typed again (the password too): saved.
        $retyped = $this->submit('/main/monitors/' . $id . '/edit', [
            'name'         => 'Moved',
            'url'          => 'https://n:new@evil.test/h',
            'proxy'        => 'http://evil-proxy.test:3128',
            'httpHeaders'  => 'Authorization: Bearer new',
            'authMethod'   => 'basic',
            'authUsername' => 'user',
            'authPassword' => 'retyped',
        ]);
        $this->bindController($controller, true, $retyped);
        self::assertTrue($controller->edit('main', $id, $retyped)->isRedirect());
        self::assertSame('https://n:new@evil.test/h', $monitor->getConfig()['url']);
        self::assertSame('retyped', $monitor->getConfig()['auth_password']);
    }

    /**
     * Since 1.25.2 (KE-U151-L1): a mask with nothing stored behind it (a renamed header, a new token parameter, a mask
     * typed into a new monitor) is refused on its field with 422; nothing is saved and no event is sent.
     */
    public function testAMaskWithNothingStoredBehindItIsRefusedOnItsField(): void
    {
        $monitor = $this->persisted('Leftover');
        $config  = ['url' => 'https://p.test/h?token=t1', 'method' => 'GET', 'headers' => ['Authorization' => 'Bearer abc']];
        $monitor->setConfig($config);
        $this->em->flush();
        $id         = (int) $monitor->getId();
        $controller = $this->controller();

        $post = $this->submit('/main/monitors/' . $id . '/edit', [
            'name'        => 'Leftover',
            'url'         => 'https://p.test/h?token=***&tokenx=***',
            'httpHeaders' => "Authorization: ***\nX-Other: ***",
            'httpBody'    => '***',
        ], ['X-Uptime-Modal' => '1']);
        $this->bindController($controller, true, $post);
        self::assertSame(422, $controller->edit('main', $id, $post)->getStatusCode());
        $form = $this->renderedContext['form'] ?? null;
        self::assertInstanceOf(FormView::class, $form);
        foreach (['url', 'httpHeaders', 'httpBody'] as $field) {
            self::assertCount(1, $form[$field]->vars['errors'], $field);
            self::assertSame('form.monitor.secret_mask_unresolved', $form[$field]->vars['errors'][0]->getMessage());
        }
        self::assertCount(0, $form['proxy']->vars['errors']);
        self::assertSame(json_encode($config), json_encode($monitor->getConfig()), 'nothing saved');
        self::assertSame([], $this->events);

        // A new monitor has nothing stored: a typed mask is refused as well.
        $new = $this->submit('/main/monitors/new', ['name' => 'Fresh', 'url' => 'https://***@p.test/', 'proxy' => '***@proxy.test:3128'], ['X-Uptime-Modal' => '1']);
        $this->bindController($controller, true, $new);
        self::assertSame(422, $controller->new('main', $new)->getStatusCode());
        $form = $this->renderedContext['form'] ?? null;
        self::assertInstanceOf(FormView::class, $form);
        self::assertSame('form.monitor.secret_mask_unresolved', $form['url']->vars['errors'][0]->getMessage());
        self::assertSame('form.monitor.secret_mask_unresolved', $form['proxy']->vars['errors'][0]->getMessage());
        self::assertNull($this->monitors->findOneBy(['name' => 'Fresh']));
        self::assertSame([], $this->events);
    }

    /**
     * Since 1.25.2 (KE-U151-L2): the stored request body is never rendered; the mask keeps it, a typed body replaces it,
     * the clear box removes it, another host asks for it again, and the event names the field only.
     */
    public function testTheRequestBodyIsWriteOnlyOnEveryEditPath(): void
    {
        $monitor = $this->persisted('Login');
        $monitor->setConfig(['url' => 'https://p.test/login', 'method' => 'POST', 'body' => '{"login":"body-fixture"}']);
        $this->em->flush();
        $id         = (int) $monitor->getId();
        $controller = $this->controller();

        $get = Request::create('/main/monitors/' . $id . '/edit');
        $this->bindController($controller, true, $get);
        $controller->edit('main', $id, $get);
        $this->assertRenderedMasks(['httpBody' => '***'], ['body-fixture']);

        $base = ['name' => 'Login', 'url' => 'https://p.test/login', 'method' => 'POST'];
        $keep = $this->submit('/main/monitors/' . $id . '/edit', $base + ['httpBody' => '***']);
        $this->bindController($controller, true, $keep);
        self::assertTrue($controller->edit('main', $id, $keep)->isRedirect());
        self::assertSame('{"login":"body-fixture"}', $monitor->getConfig()['body']);
        $event = $this->events[0] ?? null;
        self::assertInstanceOf(MonitorUpdatedEvent::class, $event);
        self::assertNotContains('httpBody', $event->changedFields);

        $moved = $this->submit('/main/monitors/' . $id . '/edit', ['url' => 'https://evil.test/login', 'httpBody' => '***'] + $base, ['X-Uptime-Modal' => '1']);
        $this->bindController($controller, true, $moved);
        self::assertSame(422, $controller->edit('main', $id, $moved)->getStatusCode());
        $form = $this->renderedContext['form'] ?? null;
        self::assertInstanceOf(FormView::class, $form);
        self::assertSame('form.monitor.secret_retype', $form['httpBody']->vars['errors'][0]->getMessage());
        self::assertSame('https://p.test/login', $monitor->getConfig()['url']);

        $this->events = [];
        $replace      = $this->submit('/main/monitors/' . $id . '/edit', $base + ['httpBody' => '{"login":"typed-body"}']);
        $this->bindController($controller, true, $replace);
        $controller->edit('main', $id, $replace);
        self::assertSame('{"login":"typed-body"}', $monitor->getConfig()['body']);
        $event = $this->events[0] ?? null;
        self::assertInstanceOf(MonitorUpdatedEvent::class, $event);
        self::assertContains('httpBody', $event->changedFields);
        self::assertStringNotContainsString('typed-body', (string) json_encode($event));

        $clear = $this->submit('/main/monitors/' . $id . '/edit', $base + ['httpBody' => '***', 'httpBodyClear' => '1']);
        $this->bindController($controller, true, $clear);
        self::assertTrue($controller->edit('main', $id, $clear)->isRedirect());
        self::assertArrayNotHasKey('body', $monitor->getConfig());
    }

    /**
     * @param array<string, string> $masked
     * @param list<string> $secrets
     */
    private function assertRenderedMasks(array $masked, array $secrets): void
    {
        $form = $this->renderedContext['form'] ?? null;
        self::assertInstanceOf(FormView::class, $form);
        foreach ($masked as $field => $value) {
            $rendered = (string) $form[$field]->vars['value'];
            self::assertSame($value, $rendered);
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $rendered);
            }
        }
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
        $request->headers->add($headers);

        return $request;
    }
}
