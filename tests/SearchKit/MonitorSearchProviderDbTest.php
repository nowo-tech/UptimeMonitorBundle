<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\SearchKit;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\SearchKitBundle\Event\SearchItemVisibilityEvent;
use Nowo\SearchKitBundle\Event\SearchKitEvents;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use Nowo\SearchKitBundle\Test\QueryCounter;
use Nowo\SearchKitBundle\Test\QueryCountingMiddleware;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchMapper;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchTenantResolverInterface;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchVisibility;
use Nowo\UptimeMonitorBundle\Search\SearchKit\MonitorSearchDefinition;
use Nowo\UptimeMonitorBundle\Security\AllowAllUptimeMonitorAccessChecker;
use Nowo\UptimeMonitorBundle\Security\MonitorAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Translation\IdentityTranslator;

use function array_map;
use function class_exists;
use function in_array;
use function json_encode;
use function str_contains;

use const JSON_UNESCAPED_SLASHES;

// SearchKit requires PHP >= 8.3 and stays optional: without it (the PHP 8.2 CI cell) this file declares nothing.
if (!class_exists(EntitySearchProviderTestCase::class)) {
    return;
}

/**
 * The SearchKit contract kit against the real MonitorSearchDefinition and a real database (SQLite by default; MySQL /
 * MariaDB / PostgreSQL through UPTIME_TEST_DATABASE_URL), plus the monitor-specific rules: the host checker's deny, the
 * SearchKit host event, credentials never shown or matched, push targets and groups.
 */
#[Group('db')]
#[CoversClass(MonitorSearchDefinition::class)]
#[CoversClass(MonitorSearchVisibility::class)]
final class MonitorSearchProviderDbTest extends EntitySearchProviderTestCase implements MonitorSearchTenantResolverInterface
{
    private EntityManagerInterface $em;
    private ManagerRegistry $registry;
    private QueryCounter $counter;
    private MonitorSearchDefinition $definition;
    private TokenStorage $tokens;

    private ?string $tenant     = null;
    private bool $dashboardOpen = true;
    /** @var list<string> names the host checker refuses to show */
    private array $hidden = [];
    /** @var list<string> names seedHidden() made the host checker refuse */
    private array $hiddenNames = [];

    protected function setUp(): void
    {
        $this->counter = new QueryCounter();
        $this->em      = SqliteEntityManagerFactory::create((new DbalConfiguration())->setMiddlewares([new QueryCountingMiddleware($this->counter)]));
        $this->em->persist(new Tenant('acme', 'Acme'));
        $this->em->persist(new Tenant('beta', 'Beta'));
        $this->em->flush();
        $this->em->clear();

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $registry->method('getManager')->willReturn($this->em);

        $this->registry = $registry;
        $this->tokens   = new TokenStorage();
        $self           = $this;
        $checker        = new class($self) implements MonitorAwareUptimeMonitorAccessCheckerInterface {
            public function __construct(private readonly MonitorSearchProviderDbTest $test)
            {
            }

            public function canAccessDashboard(object $user): bool
            {
                return $this->test->dashboardOpen();
            }

            public function canManageMonitors(object $user): bool
            {
                return false;
            }

            public function canManageSettings(object $user): bool
            {
                return false;
            }

            public function canAccessTenant(object $user, string $tenantSlug): bool
            {
                return $this->test->tenantOf($user) === $tenantSlug;
            }

            public function canManageTenantMonitors(object $user, string $tenantSlug): bool
            {
                return false;
            }

            public function canManageTenantSettings(object $user, string $tenantSlug): bool
            {
                return false;
            }

            public function canViewMonitor(object $user, Monitor $monitor): bool
            {
                return $this->canAccessTenant($user, $monitor->getTenant()->getSlug()) && !$this->test->isHidden($monitor->getName());
            }
        };

        $routes = new RouteCollection();
        $routes->add('nowo_uptime_monitor_show', new Route('/uptime/{tenantSlug}/monitors/{id}'));
        $routes->add('nowo_uptime_dashboard', new Route('/uptime/{tenantSlug}'));
        $urls = new UrlGenerator($routes, new RequestContext());

        $this->definition = new MonitorSearchDefinition(new MonitorRepository($registry), $this, new MonitorSearchVisibility($checker, $this->tokens), new MonitorSearchMapper(), $urls);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->close();
    }

    public function resolve(string $userIdentifier): ?string
    {
        return $this->tenant;
    }

    public function dashboardOpen(): bool
    {
        return $this->dashboardOpen;
    }

    public function tenantOf(object $user): ?string
    {
        return $this->tenant;
    }

    public function isHidden(string $name): bool
    {
        return in_array($name, $this->hidden, true) || in_array($name, $this->hiddenNames, true);
    }

    protected function definition(): EntitySearchDefinition
    {
        return $this->definition;
    }

    protected function queryCounter(): QueryCounter
    {
        return $this->counter;
    }

    protected function tenants(): array
    {
        return ['acme', 'beta'];
    }

    protected function seed(int|string $tenant, string $text): int
    {
        return $this->add((string) $tenant, $text, 'https://seed.example.com/');
    }

    protected function seedHidden(int|string $tenant, string $text): int
    {
        $this->hiddenNames[] = $text;

        return $this->add((string) $tenant, $text, 'https://hidden.example.com/');
    }

    protected function seedArchived(int|string $tenant, string $text): int
    {
        self::markTestSkipped('Monitors have no archived or deleted state: nothing for baseQuery() to filter out.');
    }

    protected function reference(int|string $id): SearchReference
    {
        return new SearchReference('/uptime/acme/monitors/' . $id, 'uptime_monitors', 'nowo_uptime_monitor_show', ['tenantSlug' => 'acme', 'id' => (string) $id]);
    }

    protected function actAs(int|string|null $tenant, string $userIdentifier): void
    {
        $this->tenant        = $tenant === null ? null : (string) $tenant;
        $this->dashboardOpen = true;
        $this->tokens->setToken(new UsernamePasswordToken(new InMemoryUser($userIdentifier, null), 'main'));
    }

    protected function disableFeature(): void
    {
        $this->dashboardOpen = false;
    }

    public function testAHostCheckerDenyHidesTheMonitorWithoutAHint(): void
    {
        $marker = $this->marker();
        $this->add('acme', $marker . ' visible', 'https://a.example.com');
        $this->add('acme', $marker . ' closed', 'https://b.example.com');
        $this->actAs('acme', self::VIEWER_A);
        $this->hidden = [$marker . ' closed'];

        $group = $this->provider()->search($this->query($marker));

        self::assertNotNull($group);
        self::assertSame(1, $group->total);
        self::assertSame($marker . ' visible', $group->items[0]->title);

        $this->hidden = [$marker . ' visible', $marker . ' closed'];
        self::assertNull($this->provider()->search($this->query($marker)));
    }

    public function testTheSearchKitHostEventCanDenyAMonitor(): void
    {
        $marker = $this->marker();
        $this->add('acme', $marker . ' one', 'https://a.example.com');
        $this->add('acme', $marker . ' two', 'https://b.example.com');
        $this->actAs('acme', self::VIEWER_A);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SearchKitEvents::ITEM_VISIBILITY, static function (SearchItemVisibilityEvent $event): void {
            self::assertSame('uptime_monitor', $event->subjects()[0]->type);
            $event->deny($event->subjects()[0]);
        });

        $group = $this->provider($dispatcher)->search($this->query($marker));

        self::assertNotNull($group);
        self::assertSame(1, $group->total);
    }

    public function testResultsShowTheOriginAndStatusAndNoCredentials(): void
    {
        $marker = $this->marker();
        $this->add('acme', $marker . ' api', 'https://user:hunter2@api.example.com:8443/p?token=SECRETTOKEN', CheckStatus::Down, ['headers' => ['Authorization' => 'Bearer HEADERSECRET'], 'auth_password' => 'PWSECRET']);
        $this->add('acme', $marker . ' push', 'pushtarget-SECRETPUSH', null, [], MonitorType::Push);
        $this->add('acme', $marker . ' group', '', null, [], MonitorType::Group);
        $this->actAs('acme', self::VIEWER_A);

        $group = $this->provider()->search($this->query($marker));

        self::assertNotNull($group);
        self::assertSame(3, $group->total);
        $shown = (string) json_encode($group->items, JSON_UNESCAPED_SLASHES);
        foreach (['hunter2', 'SECRETTOKEN', 'HEADERSECRET', 'PWSECRET', 'SECRETPUSH', ':8443/'] as $secret) {
            self::assertStringNotContainsString($secret, $shown, $secret);
        }

        self::assertStringContainsString('https://api.example.com:8443', $shown);
        self::assertStringContainsString('down', $shown);
        self::assertStringContainsString('/uptime/acme/monitors/', $shown);
        self::assertNotNull($group->moreUrl);
        self::assertTrue(str_contains((string) $group->moreUrl, '/uptime/acme'));
    }

    public function testAWordFoundOnlyInACredentialFindsNothing(): void
    {
        $this->add('acme', 'Plain', 'https://user:hunter2@api.example.com/x?token=SECRETTOKEN');
        $this->add('acme', 'Cron', 'pushtarget-SECRETPUSH', null, [], MonitorType::Push);
        $this->actAs('acme', self::VIEWER_A);

        foreach (['hunter2', 'SECRETTOKEN', 'SECRETPUSH', 'pushtarget'] as $needle) {
            self::assertNull($this->provider()->search($this->query($needle)), $needle);
        }

        self::assertNotNull($this->provider()->search($this->query('api.example')));
    }

    public function testDefinitionDetails(): void
    {
        $this->actAs('acme', self::VIEWER_A);
        $query = $this->query('x');

        self::assertSame('uptime_monitors', $this->definition->group());
        self::assertSame('Monitors', $this->definition->label($query));
        self::assertSame(100, $this->definition->maxCandidates());
        self::assertSame('uptime_monitor', $this->definition->subjectType());

        $this->actAs(null, self::VIEWER_A);
        self::assertNull($this->definition->seeAllUrl($query));

        $this->actAs('acme', self::VIEWER_A);
        $noRoutes = new MonitorSearchDefinition(new MonitorRepository($this->registry), $this, new MonitorSearchVisibility(new AllowAllUptimeMonitorAccessChecker()), new MonitorSearchMapper(), new UrlGenerator(new RouteCollection(), new RequestContext()), new IdentityTranslator());
        self::assertNull($noRoutes->seeAllUrl($query));
        self::assertSame('search.group', $noRoutes->label($query));
        self::assertSame(1000, (new MonitorSearchDefinition(new MonitorRepository($this->registry), $this, new MonitorSearchVisibility(new AllowAllUptimeMonitorAccessChecker()), new MonitorSearchMapper(), new UrlGenerator(new RouteCollection(), new RequestContext()), null, 5000))->maxCandidates());
    }

    public function testReferenceIdAcceptsOnlyAMonitorPageWithADigitId(): void
    {
        self::assertSame('m.id', $this->definition->idField());
        self::assertSame('12', $this->definition->referenceId($this->reference(12)));
        self::assertNull($this->definition->referenceId(new SearchReference('/x', 'uptime_monitors', 'other_route', ['id' => '12'])));
        self::assertNull($this->definition->referenceId(new SearchReference('/x', 'uptime_monitors', 'nowo_uptime_monitor_show', ['id' => '1 OR 1'])));
        self::assertNull($this->definition->referenceId(new SearchReference('/x', 'uptime_monitors', 'nowo_uptime_monitor_show')));
    }

    public function testSubtitleWithoutAStatusOrWithoutAnOrigin(): void
    {
        $marker = $this->marker();
        $this->add('acme', $marker . ' up', 'https://a.example.com');
        $this->add('acme', $marker . ' group', '', CheckStatus::Up, [], MonitorType::Group);
        $this->actAs('acme', self::VIEWER_A);

        $group = $this->provider()->search($this->query($marker));
        self::assertNotNull($group);
        $items = $group->items;

        self::assertSame(['', 'https://a.example.com'], array_map(static fn ($i): string => $i->subtitle === 'up' ? '' : $i->subtitle, $items));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function add(string $tenant, string $name, string $target, ?CheckStatus $status = null, array $config = [], MonitorType $type = MonitorType::Https): int
    {
        $tenantEntity = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => $tenant]);
        self::assertInstanceOf(Tenant::class, $tenantEntity);
        $monitor = new Monitor($tenantEntity, $name, $type, $target);
        if ($status !== null) {
            $monitor->setLastKnownStatus($status);
        }

        if ($config !== []) {
            $monitor->setConfig($config);
        }

        $this->em->persist($monitor);
        $this->em->flush();
        $id = (int) $monitor->getId();
        $this->em->clear();

        return $id;
    }
}
