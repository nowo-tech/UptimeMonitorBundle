<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Controller\SettingsController;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TagRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\DetailRetentionService;
use Nowo\UptimeMonitorBundle\Service\MonitorBackupService;
use Nowo\UptimeMonitorBundle\Service\MonitorFactory;
use Nowo\UptimeMonitorBundle\Service\TenantSettingsMapper;
use Nowo\UptimeMonitorBundle\Service\UptimeDataClearService;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\ControllerContainerTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @covers \Nowo\UptimeMonitorBundle\Controller\SettingsController
 */
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

    private function createController(EntityManagerInterface $em): SettingsController
    {
        $tenant = new Tenant('main', 'Main');

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
