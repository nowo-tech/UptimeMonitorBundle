<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Controller\SettingsController;
use Nowo\UptimeMonitorBundle\Entity\Tag;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
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
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SettingsController::class)]
final class SettingsControllerTest extends TestCase
{
    use ControllerContainerTrait;

    public function testBackupImportSkipsWhenCsrfInvalid(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $request    = $this->createImportRequest('bad');
        $controller = $this->createController($em);
        $this->bindController($controller, false, $request);

        self::assertTrue($controller->backup('main', $request)->isRedirect());
    }

    public function testBackupImportRunsWithValidCsrf(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $request    = $this->createImportRequest('valid');
        $controller = $this->createController($em);
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->backup('main', $request)->isRedirect());
    }

    public function testBackupPageRendersImportForm(): void
    {
        $controller = $this->createController($this->createMock(EntityManagerInterface::class));
        $this->bindController($controller, true, new Request());

        self::assertSame('rendered', $controller->backup('main', new Request())->getContent());
    }

    public function testBackupImportWithoutFileRedirects(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $request = Request::create('/main/settings/backup', 'POST', [
            'action'      => 'import',
            'import_mode' => 'keep',
            '_token'      => 'valid',
        ]);
        $controller = $this->createController($em);
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->backup('main', $request)->isRedirect());
    }

    public function testHistoryPageRendersActionForms(): void
    {
        $controller = $this->createController($this->createMock(EntityManagerInterface::class));
        $this->bindController($controller, true, new Request());

        self::assertSame('rendered', $controller->history('main', new Request())->getContent());
    }

    public function testPurgeHistoryWithValidCsrfRedirects(): void
    {
        $request    = Request::create('/main/settings/history/purge', 'POST', ['_token' => 'valid']);
        $controller = $this->createController(SqliteEntityManagerFactory::create());
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->purgeHistory('main', $request)->isRedirect());
        self::assertNotEmpty($this->flashBag($request)->peek('success'));
    }

    public function testPurgeHistorySkipsWhenCsrfInvalid(): void
    {
        $request    = Request::create('/main/settings/history/purge', 'POST', ['_token' => 'bad']);
        $controller = $this->createController($this->createMock(EntityManagerInterface::class));
        $this->bindController($controller, false, $request);

        self::assertTrue($controller->purgeHistory('main', $request)->isRedirect());
        self::assertSame([], $this->flashBag($request)->peek('success'));
    }

    public function testClearStatsWithValidCsrfRedirects(): void
    {
        $request    = Request::create('/main/settings/history/clear-stats', 'POST', ['_token' => 'valid']);
        $controller = $this->createController(SqliteEntityManagerFactory::create());
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->clearStats('main', $request)->isRedirect());
        self::assertNotEmpty($this->flashBag($request)->peek('success'));
    }

    public function testTagsPageAndDeleteTag(): void
    {
        $em     = SqliteEntityManagerFactory::create();
        $tenant = new Tenant('main', 'Main');
        $tag    = new Tag($tenant, 'prod');
        $em->persist($tenant);
        $em->persist($tag);
        $em->flush();
        $id = (int) $tag->getId();

        $controller = $this->createController($em, $tenant);
        $this->bindController($controller, true, new Request());
        self::assertSame('rendered', $controller->tags('main', new Request())->getContent());

        $request = Request::create('/main/settings/tags/' . $id . '/delete', 'POST', ['_token' => 'valid']);
        $this->bindController($controller, true, $request);
        self::assertTrue($controller->deleteTag('main', $id, $request)->isRedirect());

        $em->clear();
        self::assertNull($em->find(Tag::class, $id));
    }

    private function flashBag(Request $request): FlashBagInterface
    {
        $session = $request->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session->getFlashBag();
    }

    private function createImportRequest(string $token): Request
    {
        $tmp = tempnam(sys_get_temp_dir(), 'uptime-backup-');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, '{"monitors":[]}');

        $file = new UploadedFile($tmp, 'backup.json', 'application/json', null, true);

        return Request::create('/main/settings/backup', 'POST', [
            'action'      => 'import',
            'import_mode' => 'skip',
            '_token'      => $token,
        ], [], ['backup' => $file]);
    }

    private function createController(EntityManagerInterface $em, ?Tenant $tenant = null): SettingsController
    {
        $tenant ??= new Tenant('main', 'Main');

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn($tenant);

        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('findByTenantSlug')->willReturn([]);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new SettingsController(
            $translator,
            $tenantRepo,
            new TagRepository($registry),
            new TenantSettingsMapper(),
            $em,
            new MonitorBackupService($monitorRepo, new MonitorFactory($monitorRepo), $em),
            new UptimeDataClearService($em, $tenantRepo, $monitorRepo),
            new DetailRetentionService($em, $tenantRepo, ['detail_days' => 30]),
            ['detail_days' => 30],
            [],
        );
    }
}
