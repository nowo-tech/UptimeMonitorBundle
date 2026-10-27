<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Controller\SettingsController;
use Nowo\UptimeMonitorBundle\Entity\Tag;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Form\SettingsAppearanceFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsGeneralFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsHistoryFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsReverseProxyFormType;
use Nowo\UptimeMonitorBundle\Form\TagFormType;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TagRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\DetailRetentionService;
use Nowo\UptimeMonitorBundle\Service\MonitorBackupService;
use Nowo\UptimeMonitorBundle\Service\MonitorFactory;
use Nowo\UptimeMonitorBundle\Service\TenantSettingsMapper;
use Nowo\UptimeMonitorBundle\Service\UptimeDataClearService;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\ControllerContainerTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Util\StringUtil;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Every settings screen: GET renders the settings scaffold, a valid POST saves and redirects with a flash.
 */
#[CoversClass(SettingsController::class)]
final class SettingsControllerScreensTest extends TestCase
{
    use ControllerContainerTrait;

    private EntityManagerInterface $em;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em     = SqliteEntityManagerFactory::create();
        $this->tenant = new Tenant('main', 'Main');
        $this->em->persist($this->tenant);
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $notificationsConfig
     */
    private function controller(?Tenant $tenant = null, array $notificationsConfig = []): SettingsController
    {
        $tenantRepo = $this->createStub(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturnCallback(fn (string $slug): ?Tenant => $slug === 'main' ? ($tenant ?? $this->tenant) : null);
        $monitorRepo = $this->createStub(MonitorRepository::class);
        $monitorRepo->method('findByTenantSlug')->willReturn([]);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new SettingsController(
            $translator,
            $tenantRepo,
            new TagRepository($registry),
            new TenantSettingsMapper(),
            $this->em,
            new MonitorBackupService($monitorRepo, new MonitorFactory($monitorRepo), $this->em),
            new UptimeDataClearService($this->em, $tenantRepo, $monitorRepo),
            new DetailRetentionService($this->em, $tenantRepo, ['detail_days' => 30]),
            ['detail_days' => 30],
            $notificationsConfig,
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function post(string $formType, array $fields): Request
    {
        return Request::create('/main/settings', 'POST', [StringUtil::fqcnToBlockPrefix($formType) => $fields + ['_token' => 'valid']]);
    }

    /**
     * @return list<string>
     */
    private static function flashes(Request $request, string $type): array
    {
        $session = $request->getSession();
        self::assertInstanceOf(Session::class, $session);

        /** @var list<string> $messages */
        $messages = $session->getFlashBag()->peek($type);

        return $messages;
    }

    public function testIndexRedirectsToGeneral(): void
    {
        $controller = $this->controller();
        $this->bindController($controller, true, new Request());

        self::assertSame('/generated/nowo_uptime_settings_general', $controller->index('main')->getTargetUrl());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function getScreens(): iterable
    {
        yield 'general' => ['general'];
        yield 'appearance' => ['appearance'];
        yield 'reverse proxy' => ['reverseProxy'];
    }

    #[DataProvider('getScreens')]
    public function testFormScreensRenderOnGet(string $method): void
    {
        $controller = $this->controller();
        $this->bindController($controller, true, new Request());

        self::assertSame('rendered', $controller->{$method}('main', new Request())->getContent());
    }

    public function testGeneralSaves(): void
    {
        $request    = self::post(SettingsGeneralFormType::class, ['displayTimezone' => 'Europe/Madrid', 'serverTimezone' => 'UTC', 'entryPage' => 'status', 'chromiumExecutable' => 'auto']);
        $controller = $this->controller();
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->general('main', $request)->isRedirect());
        self::assertSame('Europe/Madrid', $this->tenant->getSettings()['display_timezone']);
        self::assertSame(['flash.settings.general_saved'], self::flashes($request, 'success'));
    }

    public function testAppearanceSaves(): void
    {
        $request    = self::post(SettingsAppearanceFormType::class, ['theme' => 'dark', 'heartbeatBarTheme' => 'normal', 'elapsedTime' => 'show', 'uiFramework' => 'default']);
        $controller = $this->controller();
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->appearance('main', $request)->isRedirect());
        self::assertSame('dark', $this->tenant->getSettings()['theme']);
    }

    public function testReverseProxySaves(): void
    {
        $request    = self::post(SettingsReverseProxyFormType::class, ['trustedProxy' => '1']);
        $controller = $this->controller();
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->reverseProxy('main', $request)->isRedirect());
        self::assertTrue($this->tenant->getSettings()['trusted_proxy']);
    }

    public function testHistorySaves(): void
    {
        $request    = self::post(SettingsHistoryFormType::class, ['detailDays' => '12']);
        $controller = $this->controller();
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->history('main', $request)->isRedirect());
        self::assertSame(12, $this->tenant->getSettings()['detail_days']);
    }

    public function testNotificationsScreenSummarisesTheConfiguration(): void
    {
        foreach ([[], ['enabled' => true, 'cooldown_seconds' => 60, 'email' => ['enabled' => true], 'webhook' => 'x', 'slack' => ['enabled' => false]]] as $config) {
            $controller = $this->controller(null, $config);
            $this->bindController($controller, true, new Request());

            self::assertSame('rendered', $controller->notifications('main')->getContent());
        }
    }

    public function testTagIsCreatedWithItsColour(): void
    {
        $request    = self::post(TagFormType::class, ['name' => 'prod', 'color' => '#16a34a']);
        $controller = $this->controller();
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->tags('main', $request)->isRedirect());
        $tag = $this->em->getRepository(Tag::class)->findOneBy(['name' => 'prod']);
        self::assertInstanceOf(Tag::class, $tag);
        self::assertSame('#16a34a', $tag->getColor());

        $plain = self::post(TagFormType::class, ['name' => 'eu', 'color' => '']);
        $this->bindController($controller, true, $plain);
        $controller->tags('main', $plain);
        self::assertNull($this->em->getRepository(Tag::class)->findOneBy(['name' => 'eu'])?->getColor());
    }

    public function testDeletingAnotherTenantsTagIsNotFound(): void
    {
        $other = new Tenant('other', 'Other');
        $tag   = new Tag($other, 'theirs');
        $this->em->persist($other);
        $this->em->persist($tag);
        $this->em->flush();

        $controller = $this->controller();
        $this->bindController($controller, true, new Request());

        $this->expectException(NotFoundHttpException::class);
        $controller->deleteTag('main', (int) $tag->getId(), new Request());
    }

    public function testUnknownTenantIsNotFound(): void
    {
        $controller = $this->controller();
        $this->bindController($controller, true, new Request());

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Tenant "nope" not found.');
        $controller->about('nope');
    }

    public function testAboutAndExport(): void
    {
        $controller = $this->controller();
        $this->bindController($controller, true, new Request());

        self::assertSame('rendered', $controller->about('main')->getContent());

        $export = $controller->exportBackup('main');
        self::assertSame('attachment; filename="uptime-backup-main.json"', $export->headers->get('Content-Disposition'));
        self::assertStringContainsString('"tenant":"main"', (string) $export->getContent());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function badBackups(): iterable
    {
        yield 'invalid JSON' => ['{nope'];
        yield 'scalar root' => ['42'];
    }

    #[DataProvider('badBackups')]
    public function testUnreadableBackupFlashesAnError(string $content): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'uptime-backup-');
        file_put_contents($file, $content);
        $request = Request::create('/main/settings/backup', 'POST', [
            'action'      => 'import',
            'import_mode' => 'skip',
            '_token'      => 'valid',
        ], [], ['backup' => new UploadedFile($file, 'backup.json', 'application/json', null, true)]);
        $controller = $this->controller();
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->backup('main', $request)->isRedirect());
        self::assertSame(['flash.settings.backup_import_failed'], self::flashes($request, 'error'));
    }
}
