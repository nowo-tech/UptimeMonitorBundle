<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\SettingsPage;
use Nowo\UiKitBundle\View\SettingsSection;
use Nowo\UptimeMonitorBundle\Entity\Tag;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Form\BackupImportFormType;
use Nowo\UptimeMonitorBundle\Form\CsrfActionFormType;
use Nowo\UptimeMonitorBundle\Form\Model\TagFormData;
use Nowo\UptimeMonitorBundle\Form\SettingsAppearanceFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsGeneralFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsHistoryFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsReverseProxyFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsStatusPageFormType;
use Nowo\UptimeMonitorBundle\Form\TagFormType;
use Nowo\UptimeMonitorBundle\Monitor\TenantSettings;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TagRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\DetailRetentionService;
use Nowo\UptimeMonitorBundle\Service\MonitorBackupService;
use Nowo\UptimeMonitorBundle\Service\TenantSettingsMapper;
use Nowo\UptimeMonitorBundle\Service\UptimeDataClearService;
use Nowo\UptimeMonitorBundle\Ui\SettingsSections;
use Nowo\UptimeMonitorBundle\Ui\UptimeAreaNav;
use Nowo\UptimeMonitorBundle\Ui\UptimePages;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function array_merge;
use function is_array;
use function is_string;
use function sprintf;
use function strcasecmp;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Per-tenant settings UI (Uptime Kuma Settings parity).
 */
#[Route(path: '/{tenantSlug}/settings', name: 'nowo_uptime_settings_', requirements: ['tenantSlug' => '[a-z0-9\-]+'])]
final class SettingsController extends AbstractUptimeController
{
    /**
     * Creates the settings controller with its repositories, services and configuration.
     *
     * @param TranslatorInterface $translator Translator for flash messages
     * @param LoggerInterface $logger Receives rejected backup imports (exception class only)
     * @param SettingsSections $sections The settings screens the host keeps (`web_ui.settings.sections`, since 1.13)
     * @param MonitorRepository|null $monitorRepository The tenant's monitors (Settings → Status page, since 1.13)
     */
    public function __construct(
        TranslatorInterface $translator,
        private readonly TenantRepository $tenantRepository,
        private readonly TagRepository $tagRepository,
        private readonly TenantSettingsMapper $settingsMapper,
        private readonly EntityManagerInterface $entityManager,
        private readonly MonitorBackupService $backupService,
        private readonly UptimeDataClearService $dataClearService,
        private readonly DetailRetentionService $detailRetentionService,
        /** @var array<string, mixed> $retentionConfig */
        #[Autowire('%nowo_uptime_monitor.retention%')]
        private readonly array $retentionConfig,
        /** @var array<string, mixed> $notificationsConfig */
        #[Autowire('%nowo_uptime_monitor.notifications%')]
        private readonly array $notificationsConfig,
        private readonly UptimePages $pages = new UptimePages(),
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly SettingsSections $sections = new SettingsSections(),
        private readonly ?MonitorRepository $monitorRepository = null,
        /** @var array<string, mixed> $statusPageConfig */
        #[Autowire('%nowo_uptime_monitor.status_page%')]
        private readonly array $statusPageConfig = [],
    ) {
        parent::__construct($translator);
    }

    /**
     * Redirects to the first settings screen the host keeps (General by default).
     *
     * @param string $tenantSlug Tenant slug from the route
     *
     * @throws NotFoundHttpException When the host keeps no settings screen
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $tenantSlug): RedirectResponse
    {
        $first = $this->sections->first();
        if ($first === null) {
            throw $this->createNotFoundException('No settings screen.');
        }

        return $this->redirectToRoute(UptimeAreaNav::SETTINGS_SECTIONS[$first], ['tenantSlug' => $tenantSlug]);
    }

    /**
     * Shows and saves the General settings (time zones, entry page, base URL, ...).
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param Request $request Current request (handles the form submission)
     *
     * @throws NotFoundHttpException When the tenant does not exist
     */
    #[Route('/general', name: 'general', methods: ['GET', 'POST'])]
    public function general(string $tenantSlug, Request $request): Response
    {
        $this->requireSection('general');
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

        return $this->renderSettings($tenant, 'general', '@NowoUptimeMonitorBundle/settings/general.html.twig', $form, null, [
            'form' => $form,
        ]);
    }

    /**
     * Shows and saves the Appearance settings (theme, heartbeat bar, elapsed time).
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param Request $request Current request (handles the form submission)
     *
     * @throws NotFoundHttpException When the tenant does not exist
     */
    #[Route('/appearance', name: 'appearance', methods: ['GET', 'POST'])]
    public function appearance(string $tenantSlug, Request $request): Response
    {
        $this->requireSection('appearance');
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

        return $this->renderSettings($tenant, 'appearance', '@NowoUptimeMonitorBundle/settings/appearance.html.twig', $form, null, [
            'form' => $form,
        ]);
    }

    /**
     * Shows the notification channels configured in YAML (read-only).
     *
     * @param string $tenantSlug Tenant slug from the route
     *
     * @throws NotFoundHttpException When the tenant does not exist
     */
    #[Route('/notifications', name: 'notifications', methods: ['GET'])]
    public function notifications(string $tenantSlug): Response
    {
        $this->requireSection('notifications');
        $tenant = $this->requireTenant($tenantSlug);

        return $this->renderSettings($tenant, 'notifications', '@NowoUptimeMonitorBundle/settings/notifications.html.twig', null, null, [
            'notifications_config' => $this->notificationsConfig,
            'notification_items'   => $this->notificationItems(),
        ]);
    }

    /**
     * Shows and saves the Reverse proxy settings (trusted proxy).
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param Request $request Current request (handles the form submission)
     *
     * @throws NotFoundHttpException When the tenant does not exist
     */
    #[Route('/reverse-proxy', name: 'reverse_proxy', methods: ['GET', 'POST'])]
    public function reverseProxy(string $tenantSlug, Request $request): Response
    {
        $this->requireSection('reverse_proxy');
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

        return $this->renderSettings($tenant, 'reverse_proxy', '@NowoUptimeMonitorBundle/settings/section.html.twig', $form, null, [
            'form' => $form,
        ]);
    }

    /**
     * Shows and saves who may open the public status page and the monitors it shows (since 1.13).
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param Request $request Current request (handles the form submission)
     *
     * @throws NotFoundHttpException When the tenant does not exist
     */
    #[Route('/status-page', name: 'status_page', methods: ['GET', 'POST'])]
    public function statusPage(string $tenantSlug, Request $request): Response
    {
        $this->requireSection('status_page');
        $tenant  = $this->requireTenant($tenantSlug);
        $data    = $this->settingsMapper->toStatusPageData($tenant);
        $choices = [];
        foreach ($this->monitorRepository?->findByTenantSlug($tenantSlug) ?? [] as $monitor) {
            if (!$monitor->isGroup() && $monitor->getId() !== null) {
                $choices[$monitor->getName()] = $monitor->getId();
            }
        }
        $form = $this->createForm(SettingsStatusPageFormType::class, $data, ['monitor_choices' => $choices]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->settingsMapper->applyStatusPage($tenant, $data);
            $this->entityManager->flush();
            $this->addFlash('success', $this->transMessage('flash.settings.status_page_saved'));

            return $this->redirectToRoute('nowo_uptime_settings_status_page', ['tenantSlug' => $tenantSlug]);
        }

        $public = ($this->statusPageConfig['enabled'] ?? true)
            && TenantSettings::from($tenant)->isStatusPagePublic((bool) ($this->statusPageConfig['default_public'] ?? true));

        return $this->renderSettings($tenant, 'status_page', '@NowoUptimeMonitorBundle/settings/status_page.html.twig', $form, null, [
            'form'            => $form,
            'status_page_url' => $public ? $this->generateUrl('nowo_uptime_status_page', ['tenantSlug' => $tenantSlug], UrlGeneratorInterface::ABSOLUTE_URL) : null,
            'has_monitors'    => $choices !== [],
        ]);
    }

    /**
     * Lists the tenant tags and creates a new one.
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param Request $request Current request (handles the form submission)
     *
     * @throws NotFoundHttpException When the tenant does not exist
     */
    #[Route('/tags', name: 'tags', methods: ['GET', 'POST'])]
    public function tags(string $tenantSlug, Request $request): Response
    {
        $this->requireSection('tags');

        return $this->tagsScreen($tenantSlug, $request, null);
    }

    /**
     * Shows and saves one tag of the tenant (name and colour) on the Tags screen (since 1.13).
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param int $id Tag id
     * @param Request $request Current request (handles the form submission)
     *
     * @throws NotFoundHttpException When the tenant or the tag does not exist
     */
    #[Route('/tags/{id}', name: 'tag_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editTag(string $tenantSlug, int $id, Request $request): Response
    {
        $this->requireSection('tags');

        return $this->tagsScreen($tenantSlug, $request, $id);
    }

    /**
     * Deletes a tag of the tenant (CSRF-protected).
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param int $id Tag id
     * @param Request $request Current request (CSRF token)
     *
     * @throws NotFoundHttpException When the tenant or the tag does not exist
     */
    #[Route('/tags/{id}/delete', name: 'tag_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteTag(string $tenantSlug, int $id, Request $request): RedirectResponse
    {
        $this->requireSection('tags');
        $tenant = $this->requireTenant($tenantSlug);
        $tag    = $this->tagRepository->find($id);
        if ($tag === null || $tag->getTenant()->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfActionSubmitted($this->createTagDeleteForm($tenantSlug, $id), $request)) {
            $this->entityManager->remove($tag);
            $this->entityManager->flush();
            $this->addFlash('success', $this->transMessage('flash.settings.tag_deleted'));
        }

        return $this->redirectToRoute('nowo_uptime_settings_tags', ['tenantSlug' => $tenantSlug]);
    }

    /**
     * Shows and saves the Monitor history retention, with the purge and clear actions.
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param Request $request Current request (handles the form submission)
     *
     * @throws NotFoundHttpException When the tenant does not exist
     */
    #[Route('/history', name: 'history', methods: ['GET', 'POST'])]
    public function history(string $tenantSlug, Request $request): Response
    {
        $this->requireSection('history');
        $tenant     = $this->requireTenant($tenantSlug);
        $globalDays = (int) ($this->retentionConfig['detail_days'] ?? 30);
        $data       = $this->settingsMapper->toHistoryData($tenant, $globalDays);
        $form       = $this->createForm(SettingsHistoryFormType::class, $data, [
            'global_detail_days' => $globalDays,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->settingsMapper->applyHistory($tenant, $data);
            $this->entityManager->flush();
            $this->addFlash('success', $this->transMessage('flash.settings.history_saved'));

            return $this->redirectToRoute('nowo_uptime_settings_history', ['tenantSlug' => $tenantSlug]);
        }

        $purgeForm = $this->createPurgeHistoryForm($tenantSlug);
        $clearForm = $this->createClearStatsForm($tenantSlug);

        return $this->renderSettings($tenant, 'history', '@NowoUptimeMonitorBundle/settings/history.html.twig', $form, null, [
            'form'               => $form,
            'global_detail_days' => $globalDays,
            'purge_form'         => $purgeForm,
            'clear_form'         => $clearForm,
            'history_actions'    => [
                new Action(
                    'custom',
                    UptimePages::label('settings.history.purge'),
                    $this->generateUrl('nowo_uptime_settings_history_purge', ['tenantSlug' => $tenantSlug]),
                    method: 'POST',
                    variant: 'secondary',
                    attributes: ['data-testid' => 'uptime-history-purge'],
                    form: $purgeForm->createView(),
                ),
                new Action(
                    'delete',
                    UptimePages::label('settings.history.clear'),
                    $this->generateUrl('nowo_uptime_settings_history_clear', ['tenantSlug' => $tenantSlug]),
                    method: 'POST',
                    confirm: new Confirm(
                        UptimePages::label('settings.history.clear'),
                        UptimePages::label('settings.history.clear_confirm'),
                        UptimePages::label('settings.history.clear'),
                    ),
                    attributes: ['data-testid' => 'uptime-history-clear'],
                    form: $clearForm->createView(),
                ),
            ],
        ]);
    }

    /**
     * Deletes check details older than the tenant retention (CSRF-protected).
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param Request $request Current request (CSRF token)
     *
     * @throws NotFoundHttpException When the tenant does not exist
     */
    #[Route('/history/purge', name: 'history_purge', methods: ['POST'])]
    public function purgeHistory(string $tenantSlug, Request $request): RedirectResponse
    {
        $this->requireSection('history');
        $this->requireTenant($tenantSlug);
        if ($this->isCsrfActionSubmitted($this->createPurgeHistoryForm($tenantSlug), $request)) {
            $deleted = $this->detailRetentionService->purgeExpiredDetailForTenant($tenantSlug);
            $this->addFlash('success', $this->transMessage('flash.settings.purged', ['%count%' => $deleted]));
        }

        return $this->redirectToRoute('nowo_uptime_settings_history', ['tenantSlug' => $tenantSlug]);
    }

    /**
     * Deletes every check, aggregate and incident of the tenant (CSRF-protected).
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param Request $request Current request (CSRF token)
     *
     * @throws NotFoundHttpException When the tenant does not exist
     */
    #[Route('/history/clear-stats', name: 'history_clear', methods: ['POST'])]
    public function clearStats(string $tenantSlug, Request $request): RedirectResponse
    {
        $this->requireSection('history');
        $this->requireTenant($tenantSlug);
        if ($this->isCsrfActionSubmitted($this->createClearStatsForm($tenantSlug), $request)) {
            // @igor-ignore - HTTP handler delegates to services; no controller worker state.
            $result = $this->dataClearService->clear($tenantSlug);
            $this->addFlash('success', $this->transMessage('flash.settings.cleared', [
                '%checks%'     => $result['checks'],
                '%aggregates%' => $result['aggregates'],
                '%incidents%'  => $result['incidents'],
            ]));
        }

        return $this->redirectToRoute('nowo_uptime_settings_history', ['tenantSlug' => $tenantSlug]);
    }

    /**
     * Shows the backup screen and imports an uploaded monitor backup.
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param Request $request Current request (handles the form submission)
     *
     * @throws NotFoundHttpException When the tenant does not exist
     */
    #[Route('/backup', name: 'backup', methods: ['GET', 'POST'])]
    public function backup(string $tenantSlug, Request $request): Response
    {
        $this->requireSection('backup');
        $tenant = $this->requireTenant($tenantSlug);
        $form   = $this->createForm(BackupImportFormType::class, null, [
            'action' => $this->generateUrl('nowo_uptime_settings_backup', ['tenantSlug' => $tenantSlug]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            return $this->handleImport($tenant, $form);
        }

        return $this->renderSettings($tenant, 'backup', '@NowoUptimeMonitorBundle/settings/backup.html.twig', $form, UptimePages::label('settings.backup.import_button'), [
            'form'          => $form,
            'export_action' => new Action(
                'export',
                UptimePages::label('settings.backup.export_button'),
                $this->generateUrl('nowo_uptime_settings_backup_export', ['tenantSlug' => $tenantSlug]),
                variant: 'primary',
                attributes: ['data-testid' => 'uptime-backup-export'],
            ),
        ]);
    }

    /**
     * Downloads the tenant monitors as a JSON backup file.
     *
     * @param string $tenantSlug Tenant slug from the route
     *
     * @throws NotFoundHttpException When the tenant does not exist
     */
    #[Route('/backup/export', name: 'backup_export', methods: ['GET'])]
    public function exportBackup(string $tenantSlug): JsonResponse
    {
        $this->requireSection('backup');
        $tenant  = $this->requireTenant($tenantSlug);
        $payload = $this->backupService->export($tenant);

        $response = new JsonResponse($payload);
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="uptime-backup-%s.json"', $tenantSlug),
        );

        return $response;
    }

    /**
     * Shows the About screen (product, style, tenant).
     *
     * @param string $tenantSlug Tenant slug from the route
     *
     * @throws NotFoundHttpException When the tenant does not exist
     */
    #[Route('/about', name: 'about', methods: ['GET'])]
    public function about(string $tenantSlug): Response
    {
        $this->requireSection('about');
        $tenant = $this->requireTenant($tenantSlug);

        return $this->renderSettings($tenant, 'about', '@NowoUptimeMonitorBundle/settings/about.html.twig', null, null, [
            'about_items' => [
                new DetailItem(UptimePages::label('settings.about.product'), UptimePages::label('settings.about.product_value')),
                new DetailItem(UptimePages::label('settings.about.style'), UptimePages::label('settings.about.style_value')),
                new DetailItem(UptimePages::label('settings.about.tenant'), sprintf('%s (%s)', $tenant->getName(), $tenant->getSlug())),
            ],
        ]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function handleImport(Tenant $tenant, FormInterface $form): RedirectResponse
    {
        if ($form->isValid()) {
            $file = $form->get('backup')->getData();
            if (!$file instanceof UploadedFile) {
                $this->addFlash('error', $this->transMessage('flash.settings.backup_no_file'));

                return $this->redirectToRoute('nowo_uptime_settings_backup', ['tenantSlug' => $tenant->getSlug()]);
            }

            try {
                $payload = json_decode($file->getContent(), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new JsonException('Invalid JSON root.');
                }

                $mode   = $form->get('import_mode')->getData();
                $mode   = is_string($mode) && $mode !== '' ? $mode : MonitorBackupService::IMPORT_SKIP;
                $result = $this->backupService->import($tenant, $payload, $mode);
                $this->addFlash('success', $this->transMessage('flash.settings.backup_imported', [
                    '%imported%'    => $result['imported'],
                    '%skipped%'     => $result['skipped'],
                    '%overwritten%' => $result['overwritten'],
                    '%invalid%'     => $result['invalid'],
                ]));
                if ($result['over_quota'] > 0) {
                    $this->addFlash('warning', $this->transMessage('flash.settings.backup_over_quota', ['%count%' => $result['over_quota']]));
                }
            } catch (Throwable $e) {
                // Generic message: exception texts may echo file content, SQL or paths (SEC-004 L7).
                $this->logger->warning('Uptime backup import rejected for tenant {tenant}: {error}', [
                    'bundle' => 'NowoUptimeMonitorBundle',
                    'tenant' => $tenant->getSlug(),
                    'error'  => $e::class,
                ]);
                $this->addFlash('error', $this->transMessage('flash.settings.backup_import_failed'));
            }
        }

        return $this->redirectToRoute('nowo_uptime_settings_backup', ['tenantSlug' => $tenant->getSlug()]);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function createTagDeleteForm(string $tenantSlug, int $id): FormInterface
    {
        return $this->createCsrfActionForm(
            $this->generateUrl('nowo_uptime_settings_tag_delete', ['tenantSlug' => $tenantSlug, 'id' => $id]),
            'delete-tag-' . $id,
        );
    }

    /**
     * @return FormInterface<mixed>
     */
    private function createPurgeHistoryForm(string $tenantSlug): FormInterface
    {
        return $this->createCsrfActionForm(
            $this->generateUrl('nowo_uptime_settings_history_purge', ['tenantSlug' => $tenantSlug]),
            'purge-history',
        );
    }

    /**
     * @return FormInterface<mixed>
     */
    private function createClearStatsForm(string $tenantSlug): FormInterface
    {
        return $this->createCsrfActionForm(
            $this->generateUrl('nowo_uptime_settings_history_clear', ['tenantSlug' => $tenantSlug]),
            'clear-stats',
        );
    }

    /**
     * @return FormInterface<mixed>
     */
    private function createCsrfActionForm(string $action, string $csrfTokenId): FormInterface
    {
        return $this->createForm(CsrfActionFormType::class, null, [
            'action'        => $action,
            'csrf_token_id' => $csrfTokenId,
        ]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function isCsrfActionSubmitted(FormInterface $form, Request $request): bool
    {
        // A nameless CSRF-only form has no children, so handleRequest() never auto-submits it: submit the flat POST
        // body explicitly and let the CSRF listener validate "_token" (same field and token id as before).
        $form->submit($request->request->all());

        return $form->isValid();
    }

    /**
     * Renders a settings screen on the UiKit settings scaffold: one section per screen (tabs), the current one with
     * its form (submit label: settings.save unless given).
     *
     * @param FormInterface<mixed>|null $form
     * @param array<string, mixed> $vars page variables
     */
    private function renderSettings(Tenant $tenant, string $section, string $template, ?FormInterface $form, ?Label $submitLabel, array $vars): Response
    {
        $tenantSlug = $tenant->getSlug();
        $sections   = [];
        foreach ($this->sections->all() as $id => $route) {
            $current    = $id === $section;
            $sections[] = new SettingsSection(
                $id,
                UptimePages::label('settings.nav.' . $id),
                $this->generateUrl($route, ['tenantSlug' => $tenantSlug]),
                $current ? $form?->createView() : null,
                null,
                $current ? ($submitLabel ?? UptimePages::label('settings.save')) : null,
                $this->pages->formThemes(),
            );
        }

        $page = new SettingsPage(
            new PageHeader(
                UptimePages::label('settings.title'),
                $tenant->getName(),
                back: $this->pages->backLinks() ? new BackLink($this->generateUrl('nowo_uptime_dashboard', ['tenantSlug' => $tenantSlug]), UptimePages::label('nav.dashboard')) : null,
            ),
            $sections,
            $section,
            UptimePages::BUNDLE,
            $this->pages->layout(),
            ['data-testid' => 'uptime-settings'],
        );

        // A refused form answers 422 (since 1.13), so clients and tests can tell it from a plain page.
        $status = $form instanceof FormInterface && $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render($this->pages->template('settings_' . $section, $template), $this->pages->context(array_merge($this->baseViewParams($tenant), [
            'page'          => $page,
            'section'       => $section,
            'section_title' => 'settings.section.' . $section,
        ], $vars)), new Response('', $status));
    }

    /**
     * Notification channel summary (YAML configuration) for the Notifications screen.
     *
     * @return list<DetailItem>
     */
    private function notificationItems(): array
    {
        $yesNo = static fn (mixed $enabled): Label => UptimePages::label((bool) $enabled ? 'settings.notifications.yes' : 'settings.notifications.no');
        $cfg   = $this->notificationsConfig;

        return [
            new DetailItem(UptimePages::label('settings.notifications.enabled'), $yesNo($cfg['enabled'] ?? false)),
            new DetailItem(UptimePages::label('settings.notifications.cooldown'), UptimePages::label('settings.notifications.cooldown_seconds', ['%seconds%' => (int) ($cfg['cooldown_seconds'] ?? 300)])),
            new DetailItem(UptimePages::label('settings.notifications.email'), $yesNo(is_array($cfg['email'] ?? null) ? ($cfg['email']['enabled'] ?? false) : false)),
            new DetailItem(UptimePages::label('settings.notifications.webhook'), $yesNo(is_array($cfg['webhook'] ?? null) ? ($cfg['webhook']['enabled'] ?? false) : false)),
            new DetailItem(UptimePages::label('settings.notifications.slack'), $yesNo(is_array($cfg['slack'] ?? null) ? ($cfg['slack']['enabled'] ?? false) : false)),
        ];
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

    /**
     * The Tags screen: the tenant's tags with their edit link and delete action, and the add form — or, with `$tagId`,
     * that tag's form. A name is required and unique in the tenant (case-insensitive).
     *
     * @throws NotFoundHttpException When the tenant or the tag does not exist
     */
    private function tagsScreen(string $tenantSlug, Request $request, ?int $tagId): Response
    {
        $tenant  = $this->requireTenant($tenantSlug);
        $editing = null;
        $tagData = new TagFormData();
        if ($tagId !== null) {
            $editing = $this->tagRepository->find($tagId);
            if ($editing === null || $editing->getTenant()->getId() !== $tenant->getId()) {
                throw $this->createNotFoundException();
            }
            $tagData->name  = $editing->getName();
            $tagData->color = $editing->getColor() ?? '';
        }

        $form = $this->createForm(TagFormType::class, $tagData);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            $this->validateTagName($form, $tenant, $tagData, $editing);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $tag = $editing ?? new Tag($tenant, $tagData->name);
            // @igor-ignore - Doctrine entity loaded for this request; not a shared service.
            $tag->setName($tagData->name);
            // @igor-ignore - Doctrine entity loaded for this request; not a shared service.
            $tag->setColor($tagData->color !== '' ? $tagData->color : null);
            if ($editing === null) {
                $this->entityManager->persist($tag);
            }
            $this->entityManager->flush();
            $this->addFlash('success', $this->transMessage($editing === null ? 'flash.settings.tag_created' : 'flash.settings.tag_updated'));

            return $this->redirectToRoute('nowo_uptime_settings_tags', ['tenantSlug' => $tenantSlug]);
        }

        $tags = $this->tagRepository->findByTenant($tenant);
        /** @var array<int, FormView> $deleteForms */
        $deleteForms = [];
        /** @var array<int, Action> $deleteActions */
        $deleteActions = [];
        /** @var array<int, Action> $editActions */
        $editActions = [];
        foreach ($tags as $tag) {
            $id                 = (int) $tag->getId();
            $editActions[$id]   = Action::edit($this->generateUrl('nowo_uptime_settings_tag_edit', ['tenantSlug' => $tenantSlug, 'id' => $id]), UptimePages::label('settings.tags.edit'));
            $deleteForms[$id]   = $this->createTagDeleteForm($tenantSlug, $id)->createView();
            $deleteActions[$id] = Action::delete(
                $this->generateUrl('nowo_uptime_settings_tag_delete', ['tenantSlug' => $tenantSlug, 'id' => $id]),
                null,
                new Confirm(
                    UptimePages::label('settings.tags.delete_confirm_title'),
                    UptimePages::label('settings.tags.delete_confirm', ['%name%' => $tag->getName()]),
                    UptimePages::label('settings.tags.delete'),
                ),
                UptimePages::label('settings.tags.delete'),
                $deleteForms[$id],
            );
        }

        return $this->renderSettings($tenant, 'tags', '@NowoUptimeMonitorBundle/settings/tags.html.twig', $form, UptimePages::label($editing === null ? 'settings.tags.add_button' : 'settings.save'), [
            'tags'               => $tags,
            'form'               => $form,
            'editing_tag'        => $editing,
            'tag_edit_actions'   => $editActions,
            'tag_delete_forms'   => $deleteForms,
            'tag_delete_actions' => $deleteActions,
        ]);
    }

    /**
     * Adds a `name` error for a blank tag name or one another tag of the tenant already has (case-insensitive).
     *
     * @param FormInterface<TagFormData> $form
     */
    private function validateTagName(FormInterface $form, Tenant $tenant, TagFormData $data, ?Tag $current): void
    {
        $data->name = trim($data->name);
        if ($data->name === '') {
            $form->get('name')->addError(new FormError($this->transMessage('settings.tags.name_required')));

            return;
        }
        foreach ($this->tagRepository->findByTenant($tenant) as $tag) {
            if ($tag !== $current && strcasecmp($tag->getName(), $data->name) === 0) {
                $form->get('name')->addError(new FormError($this->transMessage('settings.tags.name_taken')));

                return;
            }
        }
    }

    /**
     * A settings screen the host left out (`web_ui.settings.sections`) answers 404, its actions too.
     *
     * @throws NotFoundHttpException When the host does not keep the section
     */
    private function requireSection(string $section): void
    {
        if (!$this->sections->has($section)) {
            throw $this->createNotFoundException(sprintf('Settings screen "%s" is not available.', $section));
        }
    }

    private function requireTenant(string $tenantSlug): Tenant
    {
        $tenant = $this->tenantRepository->findOneBySlug($tenantSlug);
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException(sprintf('Tenant "%s" not found.', $tenantSlug));
        }

        return $tenant;
    }
}
