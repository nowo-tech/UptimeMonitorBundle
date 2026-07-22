<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Nowo\UptimeMonitorBundle\Entity\Tag;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Form\Model\TagFormData;
use Nowo\UptimeMonitorBundle\Form\SettingsAppearanceFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsGeneralFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsHistoryFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsReverseProxyFormType;
use Nowo\UptimeMonitorBundle\Form\TagFormType;
use Nowo\UptimeMonitorBundle\Monitor\TenantSettings;
use Nowo\UptimeMonitorBundle\Repository\TagRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\DetailRetentionService;
use Nowo\UptimeMonitorBundle\Service\MonitorBackupService;
use Nowo\UptimeMonitorBundle\Service\TenantSettingsMapper;
use Nowo\UptimeMonitorBundle\Service\UptimeDataClearService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function is_array;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Per-tenant settings UI (Uptime Kuma Settings parity).
 */
#[Route(path: '/{tenantSlug}/settings', name: 'nowo_uptime_settings_', requirements: ['tenantSlug' => '[a-z0-9\-]+'])]
final class SettingsController extends AbstractUptimeController
{
    public function __construct(
        TranslatorInterface $translator,
        private readonly TenantRepository $tenantRepository,
        private readonly TagRepository $tagRepository,
        private readonly TenantSettingsMapper $settingsMapper,
        private readonly EntityManagerInterface $entityManager,
        private readonly MonitorBackupService $backupService,
        private readonly UptimeDataClearService $dataClearService,
        private readonly DetailRetentionService $detailRetentionService,
        #[Autowire('%nowo_uptime_monitor.retention%')]
        private readonly array $retentionConfig,
        #[Autowire('%nowo_uptime_monitor.notifications%')]
        private readonly array $notificationsConfig,
    ) {
        parent::__construct($translator);
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $tenantSlug): Response
    {
        return $this->redirectToRoute('nowo_uptime_settings_general', ['tenantSlug' => $tenantSlug]);
    }

    #[Route('/general', name: 'general', methods: ['GET', 'POST'])]
    public function general(string $tenantSlug, Request $request): Response
    {
        $tenant = $this->requireTenant($tenantSlug);
        $data   = $this->settingsMapper->toGeneralData($tenant);
        $form   = $this->createForm(SettingsGeneralFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->settingsMapper->applyGeneral($tenant, $data);
            $this->entityManager->flush();
            $this->addFlash('success', $this->transMessage('flash.settings.general_saved'));

            return $this->redirectToRoute('nowo_uptime_settings_general', ['tenantSlug' => $tenantSlug]);
        }

        return $this->render('@NowoUptimeMonitorBundle/settings/general.html.twig', array_merge($this->baseViewParams($tenant), [
            'section'       => 'general',
            'section_title' => 'settings.section.general',
            'form'          => $form,
        ]));
    }

    #[Route('/appearance', name: 'appearance', methods: ['GET', 'POST'])]
    public function appearance(string $tenantSlug, Request $request): Response
    {
        $tenant = $this->requireTenant($tenantSlug);
        $data   = $this->settingsMapper->toAppearanceData($tenant);
        $form   = $this->createForm(SettingsAppearanceFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->settingsMapper->applyAppearance($tenant, $data);
            $this->entityManager->flush();
            $this->addFlash('success', $this->transMessage('flash.settings.appearance_saved'));

            return $this->redirectToRoute('nowo_uptime_settings_appearance', ['tenantSlug' => $tenantSlug]);
        }

        return $this->render('@NowoUptimeMonitorBundle/settings/appearance.html.twig', array_merge($this->baseViewParams($tenant), [
            'section'       => 'appearance',
            'section_title' => 'settings.section.appearance',
            'form'          => $form,
        ]));
    }

    #[Route('/notifications', name: 'notifications', methods: ['GET'])]
    public function notifications(string $tenantSlug): Response
    {
        $tenant = $this->requireTenant($tenantSlug);

        return $this->render('@NowoUptimeMonitorBundle/settings/notifications.html.twig', array_merge($this->baseViewParams($tenant), [
            'section'              => 'notifications',
            'section_title'        => 'settings.section.notifications',
            'notifications_config' => $this->notificationsConfig,
        ]));
    }

    #[Route('/reverse-proxy', name: 'reverse_proxy', methods: ['GET', 'POST'])]
    public function reverseProxy(string $tenantSlug, Request $request): Response
    {
        $tenant = $this->requireTenant($tenantSlug);
        $data   = $this->settingsMapper->toReverseProxyData($tenant);
        $form   = $this->createForm(SettingsReverseProxyFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->settingsMapper->applyReverseProxy($tenant, $data);
            $this->entityManager->flush();
            $this->addFlash('success', $this->transMessage('flash.settings.reverse_proxy_saved'));

            return $this->redirectToRoute('nowo_uptime_settings_reverse_proxy', ['tenantSlug' => $tenantSlug]);
        }

        return $this->renderSection($tenant, 'reverse_proxy', 'settings.section.reverse_proxy', $form);
    }

    #[Route('/tags', name: 'tags', methods: ['GET', 'POST'])]
    public function tags(string $tenantSlug, Request $request): Response
    {
        $tenant  = $this->requireTenant($tenantSlug);
        $tagData = new TagFormData();
        $form    = $this->createForm(TagFormType::class, $tagData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $tagData->name !== '') {
            $tag = new Tag($tenant, $tagData->name);
            if ($tagData->color !== '') {
                $tag->setColor($tagData->color);
            }
            $this->entityManager->persist($tag);
            $this->entityManager->flush();
            $this->addFlash('success', $this->transMessage('flash.settings.tag_created'));

            return $this->redirectToRoute('nowo_uptime_settings_tags', ['tenantSlug' => $tenantSlug]);
        }

        return $this->render('@NowoUptimeMonitorBundle/settings/tags.html.twig', array_merge($this->baseViewParams($tenant), [
            'section'       => 'tags',
            'section_title' => 'settings.section.tags',
            'tags'          => $this->tagRepository->findByTenant($tenant),
            'form'          => $form,
        ]));
    }

    #[Route('/tags/{id}/delete', name: 'tag_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteTag(string $tenantSlug, int $id, Request $request): Response
    {
        $tenant = $this->requireTenant($tenantSlug);
        $tag    = $this->tagRepository->find($id);
        if ($tag === null || $tag->getTenant()->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('delete-tag-' . $id, (string) $request->request->get('_token'))) {
            $this->entityManager->remove($tag);
            $this->entityManager->flush();
            $this->addFlash('success', $this->transMessage('flash.settings.tag_deleted'));
        }

        return $this->redirectToRoute('nowo_uptime_settings_tags', ['tenantSlug' => $tenantSlug]);
    }

    #[Route('/history', name: 'history', methods: ['GET', 'POST'])]
    public function history(string $tenantSlug, Request $request): Response
    {
        $tenant     = $this->requireTenant($tenantSlug);
        $globalDays = (int) ($this->retentionConfig['detail_days'] ?? 30);
        $data       = $this->settingsMapper->toHistoryData($tenant, $globalDays);
        $form       = $this->createForm(SettingsHistoryFormType::class, $data, [
            'global_detail_days' => $globalDays,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->settingsMapper->applyHistory($tenant, $data, $globalDays);
            $this->entityManager->flush();
            $this->addFlash('success', $this->transMessage('flash.settings.history_saved'));

            return $this->redirectToRoute('nowo_uptime_settings_history', ['tenantSlug' => $tenantSlug]);
        }

        return $this->render('@NowoUptimeMonitorBundle/settings/history.html.twig', array_merge($this->baseViewParams($tenant), [
            'section'            => 'history',
            'section_title'      => 'settings.section.history',
            'form'               => $form,
            'global_detail_days' => $globalDays,
        ]));
    }

    #[Route('/history/purge', name: 'history_purge', methods: ['POST'])]
    public function purgeHistory(string $tenantSlug, Request $request): Response
    {
        $this->requireTenant($tenantSlug);
        if ($this->isCsrfTokenValid('purge-history', (string) $request->request->get('_token'))) {
            $deleted = $this->detailRetentionService->purgeExpiredDetailForTenant($tenantSlug);
            $this->addFlash('success', $this->transMessage('flash.settings.purged', ['%count%' => $deleted]));
        }

        return $this->redirectToRoute('nowo_uptime_settings_history', ['tenantSlug' => $tenantSlug]);
    }

    #[Route('/history/clear-stats', name: 'history_clear', methods: ['POST'])]
    public function clearStats(string $tenantSlug, Request $request): Response
    {
        $this->requireTenant($tenantSlug);
        if ($this->isCsrfTokenValid('clear-stats', (string) $request->request->get('_token'))) {
            $result = $this->dataClearService->clear($tenantSlug);
            $this->addFlash('success', $this->transMessage('flash.settings.cleared', [
                '%checks%'     => $result['checks'],
                '%aggregates%' => $result['aggregates'],
                '%incidents%'  => $result['incidents'],
            ]));
        }

        return $this->redirectToRoute('nowo_uptime_settings_history', ['tenantSlug' => $tenantSlug]);
    }

    #[Route('/backup', name: 'backup', methods: ['GET', 'POST'])]
    public function backup(string $tenantSlug, Request $request): Response
    {
        $tenant = $this->requireTenant($tenantSlug);

        if ($request->isMethod('POST') && $request->request->get('action') === 'import') {
            return $this->handleImport($tenant, $request);
        }

        return $this->render('@NowoUptimeMonitorBundle/settings/backup.html.twig', array_merge($this->baseViewParams($tenant), [
            'section'       => 'backup',
            'section_title' => 'settings.section.backup',
        ]));
    }

    #[Route('/backup/export', name: 'backup_export', methods: ['GET'])]
    public function exportBackup(string $tenantSlug): JsonResponse
    {
        $tenant  = $this->requireTenant($tenantSlug);
        $payload = $this->backupService->export($tenant);

        $response = new JsonResponse($payload);
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="uptime-backup-%s.json"', $tenantSlug),
        );

        return $response;
    }

    #[Route('/about', name: 'about', methods: ['GET'])]
    public function about(string $tenantSlug): Response
    {
        $tenant = $this->requireTenant($tenantSlug);

        return $this->render('@NowoUptimeMonitorBundle/settings/about.html.twig', array_merge($this->baseViewParams($tenant), [
            'section'       => 'about',
            'section_title' => 'settings.section.about',
        ]));
    }

    private function handleImport(Tenant $tenant, Request $request): Response
    {
        $file = $request->files->get('backup');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', $this->transMessage('flash.settings.backup_no_file'));

            return $this->redirectToRoute('nowo_uptime_settings_backup', ['tenantSlug' => $tenant->getSlug()]);
        }

        try {
            $payload = json_decode($file->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new JsonException('Invalid JSON root.');
            }

            $mode   = (string) $request->request->get('import_mode', MonitorBackupService::IMPORT_SKIP);
            $result = $this->backupService->import($tenant, $payload, $mode);
            $this->addFlash('success', $this->transMessage('flash.settings.backup_imported', [
                '%imported%'    => $result['imported'],
                '%skipped%'     => $result['skipped'],
                '%overwritten%' => $result['overwritten'],
            ]));
        } catch (Throwable $e) {
            $this->addFlash('error', $this->transMessage('flash.settings.backup_import_failed', ['%error%' => $e->getMessage()]));
        }

        return $this->redirectToRoute('nowo_uptime_settings_backup', ['tenantSlug' => $tenant->getSlug()]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderSection(
        Tenant $tenant,
        string $section,
        string $title,
        FormInterface $form,
    ): Response {
        return $this->render('@NowoUptimeMonitorBundle/settings/section.html.twig', array_merge($this->baseViewParams($tenant), [
            'section'       => $section,
            'section_title' => $title,
            'form'          => $form,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseViewParams(Tenant $tenant): array
    {
        $settings = TenantSettings::from($tenant);

        return [
            'tenant'              => $tenant,
            'tenant_slug'         => $tenant->getSlug(),
            'uptime_theme'        => $settings->getTheme(),
            'uptime_search_index' => $settings->isSearchEngineIndexAllowed(),
        ];
    }

    private function requireTenant(string $tenantSlug): Tenant
    {
        $tenant = $this->tenantRepository->findOneBySlug($tenantSlug);
        if ($tenant === null) {
            throw $this->createNotFoundException(sprintf('Tenant "%s" not found.', $tenantSlug));
        }

        return $tenant;
    }
}
