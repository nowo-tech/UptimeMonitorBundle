<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\DetailSection;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\UptimeMonitorBundle\Check\PushCheckRunner;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorCreatedEvent;
use Nowo\UptimeMonitorBundle\Event\MonitorDeletedEvent;
use Nowo\UptimeMonitorBundle\Event\MonitorPausedEvent;
use Nowo\UptimeMonitorBundle\Event\MonitorQuotaCheckEvent;
use Nowo\UptimeMonitorBundle\Event\MonitorUpdatedEvent;
use Nowo\UptimeMonitorBundle\Event\UptimePageEvent;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Form\MonitorFormType;
use Nowo\UptimeMonitorBundle\Limits\TenantQuota;
use Nowo\UptimeMonitorBundle\Push\PushCredentials;
use Nowo\UptimeMonitorBundle\Push\PushMetricsSeries;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Security\MonitorReadScope;
use Nowo\UptimeMonitorBundle\Security\SecretRedactor;
use Nowo\UptimeMonitorBundle\Service\AggregateChartService;
use Nowo\UptimeMonitorBundle\Service\DashboardViewBuilder;
use Nowo\UptimeMonitorBundle\Service\MonitorFactory;
use Nowo\UptimeMonitorBundle\Ui\UptimeActions;
use Nowo\UptimeMonitorBundle\Ui\UptimePages;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_keys;
use function array_reverse;
use function array_slice;
use function array_values;
use function count;
use function get_object_vars;
use function in_array;
use function is_array;
use function is_int;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Monitor detail, create, edit, delete and pause actions of a tenant (page and dialog flows).
 */
#[Route(path: '/{tenantSlug}/monitors', name: 'nowo_uptime_monitor_', requirements: ['tenantSlug' => '[a-z0-9\-]+'])]
final class MonitorController extends AbstractUptimeController
{
    /**
     * Creates the monitor controller with its repositories and services.
     *
     * @param TranslatorInterface $translator Translator for flash messages
     * @param UptimeActions|null $actions Page actions for the users allowed to use them (since 1.12)
     * @param TenantQuota|null $quota Per-tenant monitor limits (since 1.12)
     * @param EventDispatcherInterface|null $eventDispatcher Dispatches {@see UptimePageEvent} (since 1.12)
     * @param array<string, mixed> $webUi Processed `web_ui` config (`per_page`)
     * @param list<string> $hiddenFields `form.hidden_fields`: monitor fields the host left out
     * @param PushCredentials|null $pushCredentials Issues and revokes push tokens (since 1.14)
     * @param PushMetricsSeries|null $pushSeries Server health view of push monitors (since 1.14)
     * @param MonitorReadScope|null $readScope Narrows the monitor page for users without tenant access (since 1.21)
     * @param TokenStorageInterface|null $tokenStorage Actor of the lifecycle events (since 1.25; null without SecurityBundle)
     * @param ClockInterface $clock Occurred-at of the lifecycle events (since 1.25)
     */
    public function __construct(
        TranslatorInterface $translator,
        private readonly TenantRepository $tenantRepository,
        private readonly MonitorRepository $monitorRepository,
        private readonly CheckResultRepository $checkResultRepository,
        private readonly MonitorFactory $monitorFactory,
        private readonly AggregateChartService $chartService,
        private readonly DashboardViewBuilder $dashboardViewBuilder,
        private readonly EntityManagerInterface $entityManager,
        private readonly UptimePages $pages = new UptimePages(),
        private readonly ?UptimeActions $actions = null,
        private readonly ?TenantQuota $quota = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        #[Autowire('%nowo_uptime_monitor.web_ui%')]
        private readonly array $webUi = [],
        #[Autowire('%nowo_uptime_monitor.form.hidden_fields%')]
        private readonly array $hiddenFields = [],
        private readonly ?PushCredentials $pushCredentials = null,
        private readonly ?PushMetricsSeries $pushSeries = null,
        private readonly ?MonitorReadScope $readScope = null,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
        parent::__construct($translator);
    }

    /**
     * Renders the monitor detail page (history bar, uptime, latency and daily charts, the check log paginated by
     * `web_ui.per_page`, newest first).
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param int $id Monitor id
     * @param Request|null $request Current request (`page` of the check log)
     *
     * @throws NotFoundHttpException When the monitor does not belong to the tenant
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(string $tenantSlug, int $id, ?Request $request = null): Response
    {
        return $this->renderShow($tenantSlug, $this->requireMonitor($tenantSlug, $id), $request);
    }

    /**
     * Issues a new push token (the previous one stops working) and shows it once on the monitor page, never stored
     * and never cached (CSRF-protected; push monitors only).
     *
     * @throws NotFoundHttpException When the monitor is not a push monitor of the tenant
     */
    #[Route('/{id}/push-token', name: 'push_token', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function pushToken(string $tenantSlug, int $id, Request $request): Response
    {
        $monitor = $this->requireUiManaged($this->requirePushMonitor($tenantSlug, $id));
        if (!$this->isCsrfTokenValid('push_token' . $id, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('nowo_uptime_monitor_show', ['tenantSlug' => $tenantSlug, 'id' => $id]);
        }
        $credentials = $this->pushCredentials ?? throw new NotFoundHttpException();
        $token       = $credentials->issueToken($monitor);
        $this->entityManager->flush();

        $response = $this->renderShow($tenantSlug, $monitor, $request, [
            'push_secret'         => $token,
            'push_signing_secret' => ($monitor->getConfig()['push_signed'] ?? false) === true ? $credentials->signingSecret($monitor) : null,
        ]);
        // @igor-ignore - Response built for this request only; a one-time token must never be cached.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Revokes the push token: every push is refused until a new one is issued (CSRF-protected).
     *
     * @throws NotFoundHttpException When the monitor is not a push monitor of the tenant
     */
    #[Route('/{id}/push-revoke', name: 'push_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function pushRevoke(string $tenantSlug, int $id, Request $request): RedirectResponse
    {
        $monitor = $this->requireUiManaged($this->requirePushMonitor($tenantSlug, $id));
        if ($this->isCsrfTokenValid('push_revoke' . $id, (string) $request->request->get('_token'))) {
            $this->pushCredentials?->revoke($monitor);
            $this->entityManager->flush();
            $this->addFlash('success', $this->transMessage('flash.monitor.push_revoked'));
        }

        return $this->redirectToRoute('nowo_uptime_monitor_show', ['tenantSlug' => $tenantSlug, 'id' => $id]);
    }

    /**
     * @param array<string, mixed> $extra one-time context (a token just issued)
     */
    private function renderShow(string $tenantSlug, Monitor $monitor, ?Request $request, array $extra = []): Response
    {
        $id     = (int) $monitor->getId();
        $series = $this->chartService->buildMonitorSeries($monitor, AggregatePeriod::Day, 30);
        $detail = $this->dashboardViewBuilder->buildMonitorDetail($monitor);
        $latest = $this->checkResultRepository->findLatestForMonitor($monitor);
        $scope  = $this->readScope;
        if ($scope instanceof MonitorReadScope) {
            // Only the group children the user may view (GU-L2).
            $detail['children'] = array_values(array_filter($detail['children'], static fn (array $child): bool => $scope->canView($child['monitor'])));
        }
        $event   = $this->dispatchPageEvent(new UptimePageEvent('monitor_show', $tenantSlug, $monitor));
        $log     = array_reverse($detail['events']);
        $perPage = $this->perPage();
        $pager   = Pagination::fromTotal(count($log), $request?->query->getInt('page', 1) ?? 1, $perPage, [$perPage], variant: Pagination::VARIANT_COMPACT);

        return $this->render($this->pages->template('monitor_show', '@NowoUptimeMonitorBundle/monitor/show.html.twig'), $this->pages->context([
            'page'           => $this->buildShowPage($tenantSlug, $monitor, $event),
            'page_sections'  => ['before' => $event->getSections('before'), 'after' => $event->getSections('after')],
            'log_events'     => array_slice($log, ($pager->page - 1) * $perPage, $perPage),
            'log_pagination' => $pager,
            'tenant_slug'    => $tenantSlug,
            'monitor'        => $monitor,
            // Since 1.21: what a slot template should print of the target and config (`monitor` holds them unmasked).
            'monitor_safe' => [
                'target' => SecretRedactor::maskUrl($monitor->getTarget()),
                'config' => SecretRedactor::redactMonitorConfig($monitor->getConfig()),
            ],
            'latest'              => $latest,
            'detail'              => $detail,
            'chart_series_json'   => json_encode($series, JSON_THROW_ON_ERROR),
            'latency_series_json' => json_encode($detail['latency_series'], JSON_THROW_ON_ERROR),
            'api_history_url'     => $this->generateUrl('nowo_uptime_api_monitor_history', [
                'tenantSlug' => $tenantSlug,
                'id'         => $id,
            ]),
            'api_aggregates_url' => $this->generateUrl('nowo_uptime_api_monitor_aggregates', [
                'tenantSlug' => $tenantSlug,
                'id'         => $id,
            ]),
            // A token just issued comes with its URL: only a manager reaches that action.
            'push'           => $this->pushView($monitor, $request, $extra !== [] || $this->seesTenant($tenantSlug)),
            'monitor_layout' => $this->monitorShowLayout(),
        ] + $extra));
    }

    /**
     * Whether the user reads the tenant as a whole, not just monitors shared with them (GU-L2).
     */
    private function seesTenant(string $tenantSlug): bool
    {
        return !$this->readScope instanceof MonitorReadScope || $this->readScope->seesTenant($tenantSlug);
    }

    /**
     * Server health view of a push monitor (null for other types): ingest URL (null for a user who may not see it),
     * token state, gauges and chart series of the `range` (hours) query.
     *
     * @return array<string, mixed>|null
     */
    private function pushView(Monitor $monitor, ?Request $request, bool $withUrl): ?array
    {
        if ($monitor->getType() !== MonitorType::Push || !$this->pushSeries instanceof PushMetricsSeries || $monitor->getPushKey() === null) {
            return null;
        }
        $hours  = $request?->query->getInt('range', 24) ?? 24;
        $series = $this->pushSeries->series($monitor, $hours);

        return [
            'url'         => $withUrl ? $this->generateUrl('nowo_uptime_push', ['key' => $monitor->getPushKey()], UrlGeneratorInterface::ABSOLUTE_URL) : null,
            'has_token'   => $monitor->getPushTokenHash() !== null,
            'signed'      => ($monitor->getConfig()['push_signed'] ?? false) === true,
            'last_at'     => $monitor->getPushLastAt(),
            'grace'       => PushCheckRunner::graceSeconds($monitor),
            'gauges'      => $this->pushSeries->gauges($monitor),
            'series'      => $series,
            'series_json' => json_encode($series, JSON_THROW_ON_ERROR),
            'ranges'      => PushMetricsSeries::RANGES,
        ];
    }

    /**
     * A monitor the UI may change (since 1.18): a file-managed one (`nowo:uptime:apply-monitors`) answers 409 to
     * edit, delete and the token actions, since the next apply would revert them. Pause / resume stays allowed.
     *
     * @throws ConflictHttpException When a monitors file owns the monitor
     */
    private function requireUiManaged(Monitor $monitor): Monitor
    {
        if ($monitor->isFileManaged()) {
            throw new ConflictHttpException($this->transMessage('flash.monitor.file_managed'));
        }

        return $monitor;
    }

    private function requirePushMonitor(string $tenantSlug, int $id): Monitor
    {
        $monitor = $this->requireMonitor($tenantSlug, $id);
        if ($monitor->getType() !== MonitorType::Push) {
            throw new NotFoundHttpException('Not a push monitor.');
        }

        return $monitor;
    }

    /**
     * Shows and saves the new-monitor form; with `X-Uptime-Modal: 1` it renders the dialog body and answers JSON on success.
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param Request $request Current request (form submission)
     *
     * @throws NotFoundHttpException When the tenant does not exist
     */
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(string $tenantSlug, Request $request): Response
    {
        $tenant = $this->requireTenant($tenantSlug);
        $data   = new MonitorFormData();
        $form   = $this->createForm(MonitorFormType::class, $data, [
            'group_choices'        => $this->buildGroupChoices($tenantSlug),
            'min_interval_seconds' => $this->minIntervalSeconds($tenantSlug),
        ]);
        $form->handleRequest($request);
        // A full tenant: the form says why before anything is typed, and refuses the monitor.
        if ($this->quota instanceof TenantQuota && !$this->quota->canAdd($tenantSlug)) {
            $form->addError(new FormError($this->transMessage('monitor.limits.max_monitors', ['%count%' => $this->quota->max($tenantSlug)])));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $monitor = $this->monitorFactory->createFromFormData($tenant, $data);
            $check   = new MonitorQuotaCheckEvent(MonitorQuotaCheckEvent::ACTION_CREATE, $tenantSlug, $monitor->getType());
            if (!$this->saveUnderQuota($tenant, fn () => $this->entityManager->persist($monitor), $data->type !== MonitorType::Group, [$check])) {
                $form->addError(new FormError($check->getReason() ?? $this->transMessage('monitor.limits.max_monitors', ['%count%' => $this->quota?->max($tenantSlug) ?? 0])));

                return $this->renderMonitorForm($request, $tenantSlug, $form, 'monitor.new');
            }
            $this->eventDispatcher?->dispatch(new MonitorCreatedEvent($tenantSlug, (int) $monitor->getId(), $this->actorId(), $this->clock->now()));

            $message = $this->transMessage('flash.monitor.created', ['%name%' => $monitor->getName()]);

            if ($this->isModalRequest($request)) {
                return new JsonResponse([
                    'ok'      => true,
                    'message' => $message,
                    'return'  => $this->modalReturnTarget($request),
                ]);
            }

            $this->addFlash('success', $message);

            return $this->redirectToRoute('nowo_uptime_dashboard', ['tenantSlug' => $tenantSlug]);
        }

        return $this->renderMonitorForm($request, $tenantSlug, $form, 'monitor.new');
    }

    /**
     * Shows and saves the monitor form; with `X-Uptime-Modal: 1` it renders the dialog body and answers JSON on success.
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param int $id Monitor id
     * @param Request $request Current request
     *
     * @throws NotFoundHttpException When the monitor does not belong to the tenant
     */
    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(string $tenantSlug, int $id, Request $request): Response
    {
        $monitor = $this->requireMonitor($tenantSlug, $id);
        if ($monitor->isFileManaged() && $this->isModalRequest($request)) {
            // A stale page offered Edit on a file-managed monitor: the dialog shows this text (still a 409).
            return new Response($this->transMessage('flash.monitor.file_managed'), Response::HTTP_CONFLICT, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }
        $monitor = $this->requireUiManaged($monitor);
        $data    = $this->monitorFactory->toFormData($monitor);
        // The secrets are write-only (blank or masked in the form data): compare the stored values (names only leave).
        $before = $this->storedSecrets($monitor) + get_object_vars($data);
        $form   = $this->createForm(MonitorFormType::class, $data, [
            'group_choices'        => $this->buildGroupChoices($tenantSlug, $monitor),
            'min_interval_seconds' => $this->minIntervalSeconds($tenantSlug),
        ]);
        $form->handleRequest($request);

        // A group (not counted) turned into a check takes one slot of the tenant quota (SEC-004 Q2).
        $takesSlot = $monitor->isGroup() && $data->type !== MonitorType::Group;
        if ($form->isSubmitted() && $takesSlot && $this->quota instanceof TenantQuota && !$this->quota->canAdd($tenantSlug)) {
            $form->addError(new FormError($this->transMessage('monitor.limits.max_monitors', ['%count%' => $this->quota->max($tenantSlug)])));
        }

        // Since 1.25.1: a stored secret is not carried to another scheme, host or port; its field asks for it again.
        $retype = false;
        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($this->monitorFactory->secretsNeedingRetype($monitor, $data) as $field) {
                // A field the host hid (`form.hidden_fields`) cannot be typed: that secret is dropped instead.
                if ($form->has($field)) {
                    $form->get($field)->addError(new FormError($this->transMessage('form.monitor.secret_retype')));
                    $retype = true;
                }
            }
        }

        if (!$retype && $form->isSubmitted() && $form->isValid()) {
            // Since 1.21: the same host quota as a new monitor or the pause action (GU-M1).
            $checks = MonitorQuotaCheckEvent::forChange($tenantSlug, $monitor, $data->type, $data->paused);
            if (!$this->saveUnderQuota($monitor->getTenant(), fn () => $this->monitorFactory->applyFormData($monitor, $data), $takesSlot, $checks)) {
                $form->addError(new FormError($this->denialReason($checks) ?? $this->transMessage('monitor.limits.max_monitors', ['%count%' => $this->quota?->max($tenantSlug) ?? 0])));

                return $this->renderMonitorForm($request, $tenantSlug, $form, 'monitor.edit', $monitor);
            }
            $this->eventDispatcher?->dispatch(new MonitorUpdatedEvent(
                $tenantSlug,
                (int) $monitor->getId(),
                $this->changedFields($before, $this->storedSecrets($monitor) + get_object_vars($this->monitorFactory->toFormData($monitor))),
                $this->actorId(),
                $this->clock->now(),
            ));

            $message = $this->transMessage('flash.monitor.updated', ['%name%' => $monitor->getName()]);

            if ($this->isModalRequest($request)) {
                return new JsonResponse([
                    'ok'      => true,
                    'message' => $message,
                    'return'  => $this->modalReturnTarget($request),
                ]);
            }

            $this->addFlash('success', $message);

            return $this->redirectToRoute('nowo_uptime_dashboard', ['tenantSlug' => $tenantSlug]);
        }

        return $this->renderMonitorForm($request, $tenantSlug, $form, 'monitor.edit', $monitor);
    }

    /**
     * Renders the monitor form page, or the dialog body (422 once submitted) for `X-Uptime-Modal: 1`.
     *
     * @param FormInterface<mixed> $form
     */
    private function renderMonitorForm(Request $request, string $tenantSlug, FormInterface $form, string $title, ?Monitor $monitor = null): Response
    {
        $extra = $monitor instanceof Monitor ? ['monitor' => $monitor] : [];
        if ($this->isModalRequest($request)) {
            $status = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

            return $this->render('@NowoUptimeMonitorBundle/monitor/_monitor_form_modal.html.twig', $this->pages->context([
                'tenant_slug' => $tenantSlug,
                'form'        => $form,
            ] + $extra), new Response('', $status));
        }

        return $this->render($this->pages->template('monitor_form', '@NowoUptimeMonitorBundle/monitor/form.html.twig'), $this->pages->context([
            'page'        => $this->buildFormPage($tenantSlug, $form, $title),
            'tenant_slug' => $tenantSlug,
            'form'        => $form,
            'title'       => $title,
        ] + $extra));
    }

    /**
     * Runs a write. When it takes a slot of the tenant quota or has host checks ({@see MonitorQuotaCheckEvent}, since
     * 1.21) it runs under the tenant row lock, with the quota counted again and the checks dispatched inside (SEC-004
     * Q3, GU-L1: no count-then-write race). False, with nothing written, when the tenant is full or a check is denied.
     *
     * @param callable(): mixed $apply The change, flushed after it
     * @param list<MonitorQuotaCheckEvent> $checks
     */
    private function saveUnderQuota(Tenant $tenant, callable $apply, bool $takesSlot, array $checks = []): bool
    {
        $write = function (?int $remaining) use ($apply, $takesSlot, $checks): bool {
            if ($takesSlot && $remaining === 0) {
                return false;
            }
            foreach ($checks as $check) {
                if ($this->dispatchQuotaCheck($check)->isDenied()) {
                    return false;
                }
            }
            $apply();
            $this->entityManager->flush();

            return true;
        };

        if (($takesSlot || $checks !== []) && $this->quota instanceof TenantQuota) {
            return $this->quota->withTenantLock($this->entityManager, $tenant, $write);
        }

        return $write(null);
    }

    /**
     * The text of the first denied check; null when none was denied.
     *
     * @param list<MonitorQuotaCheckEvent> $checks
     */
    private function denialReason(array $checks): ?string
    {
        foreach ($checks as $check) {
            if ($check->isDenied()) {
                return $check->getReason();
            }
        }

        return null;
    }

    /**
     * Deletes a monitor (CSRF-protected) and returns to the dashboard.
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param int $id Monitor id
     * @param Request $request Current request (CSRF token)
     *
     * @throws NotFoundHttpException When the monitor does not belong to the tenant
     */
    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(string $tenantSlug, int $id, Request $request): RedirectResponse
    {
        $monitor = $this->requireUiManaged($this->requireMonitor($tenantSlug, $id));

        if ($this->isCsrfTokenValid('delete' . $monitor->getId(), (string) $request->request->get('_token'))) {
            $monitorId = (int) $monitor->getId();
            $this->entityManager->remove($monitor);
            $this->entityManager->flush();
            $this->eventDispatcher?->dispatch(new MonitorDeletedEvent($tenantSlug, $monitorId, $this->actorId(), $this->clock->now()));
            $this->addFlash('success', $this->transMessage('flash.monitor.deleted'));
        }

        return $this->redirectToRoute('nowo_uptime_dashboard', ['tenantSlug' => $tenantSlug]);
    }

    /**
     * Pauses or resumes a monitor (CSRF-protected) and returns to the dashboard.
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param int $id Monitor id
     * @param Request $request Current request (CSRF token)
     *
     * @throws NotFoundHttpException When the monitor does not belong to the tenant
     */
    #[Route('/{id}/toggle-pause', name: 'toggle_pause', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function togglePause(string $tenantSlug, int $id, Request $request): RedirectResponse
    {
        $monitor = $this->requireMonitor($tenantSlug, $id);

        if ($this->isCsrfTokenValid('toggle' . $monitor->getId(), (string) $request->request->get('_token'))) {
            // Since 1.21: a host quota may refuse the resume (MonitorQuotaCheckEvent), checked under the tenant row lock;
            // the reason becomes a flash. Pausing is never checked.
            $checks = MonitorQuotaCheckEvent::forChange($tenantSlug, $monitor, $monitor->getType(), false);
            // @igor-ignore - HTTP handler delegates to services; no controller worker state.
            if (!$this->saveUnderQuota($monitor->getTenant(), static fn (): Monitor => $monitor->setPaused(!$monitor->isPaused()), false, $checks)) {
                $this->addFlash('error', (string) $this->denialReason($checks));
            } else {
                $this->eventDispatcher?->dispatch(new MonitorPausedEvent($tenantSlug, (int) $monitor->getId(), $monitor->isPaused(), $this->actorId(), $this->clock->now()));
            }
        }

        // From the monitor page (`?return=show`), back to it.
        if ($request->query->get('return') === 'show') {
            return $this->redirectToRoute('nowo_uptime_monitor_show', ['tenantSlug' => $tenantSlug, 'id' => $id]);
        }

        return $this->redirectToRoute('nowo_uptime_dashboard', ['tenantSlug' => $tenantSlug]);
    }

    /**
     * Monitor detail view model: name, target, back link, the monitor actions of {@see UptimeActions} (edit opens the
     * monitor form dialog, a plain link without JavaScript; pause / resume; delete behind a confirm) and the host's
     * ({@see UptimePageEvent}), and the details section (the project only when the form keeps that field).
     */
    private function buildShowPage(string $tenantSlug, Monitor $monitor, UptimePageEvent $event): ShowPage
    {
        $parent  = $monitor->getParent();
        $actions = match (true) {
            $this->actions instanceof UptimeActions => $this->actions->monitor($tenantSlug, $monitor),
            $monitor->isFileManaged()               => [],
            default                                 => [$this->editAction($tenantSlug, $monitor)],
        };
        $pushActions = $this->actions?->push($tenantSlug, $monitor) ?? [];
        $items       = [new DetailItem(UptimePages::label('monitor.show.type'), $monitor->getType()->value, badge: 'neutral')];
        // A user shown only this monitor does not get the name of its group (GU-L2): no group row, no project row instead.
        $hideParent = $parent instanceof Monitor && !$this->seesTenant($tenantSlug);
        if (!$hideParent && ($parent instanceof Monitor || !in_array('project', $this->hiddenFields, true))) {
            $items[] = new DetailItem(
                UptimePages::label($parent instanceof Monitor ? 'monitor.show.group' : 'monitor.show.project'),
                $parent instanceof Monitor ? $parent->getName() : $monitor->getProject(),
                $parent instanceof Monitor && $parent->getId() !== null ? $this->generateUrl('nowo_uptime_monitor_show', ['tenantSlug' => $tenantSlug, 'id' => $parent->getId()]) : null,
            );
        }
        $items[] = new DetailItem(UptimePages::label('monitor.show.target'), SecretRedactor::maskUrl($monitor->getTarget()));
        if ($monitor->isFileManaged()) {
            $items[] = new DetailItem(UptimePages::label('monitor.origin.label'), UptimePages::label('monitor.origin.file_badge', ['%key%' => (string) $monitor->getOriginKey()]), badge: 'info');
            $items[] = new DetailItem(UptimePages::label('monitor.origin.managed'), UptimePages::label('monitor.origin.file_explanation'));
        }
        $items[] = new DetailItem(UptimePages::label('monitor.show.interval_label'), UptimePages::label('monitor.show.seconds', ['%seconds%' => $monitor->getIntervalSeconds()]));

        return new ShowPage(
            new PageHeader(
                $monitor->getName(),
                SecretRedactor::maskUrl($monitor->getTarget()),
                [...$actions, ...$pushActions, ...$event->getActions()],
                back: $this->pages->backLinks() ? new BackLink($this->dashboardUrl($tenantSlug), UptimePages::label('nav.dashboard')) : null,
            ),
            [
                new DetailSection(UptimePages::label('monitor.show.details'), $items, 'uptime-monitor-details'),
            ],
            bundle: UptimePages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'uptime-monitor'],
        );
    }

    /**
     * Monitor create / edit view model (form scaffold).
     *
     * @param FormInterface<mixed> $form
     */
    private function buildFormPage(string $tenantSlug, FormInterface $form, string $title): FormPage
    {
        $dashboardUrl = $this->dashboardUrl($tenantSlug);

        return new FormPage(
            new PageHeader(UptimePages::label($title), back: $this->pages->backLinks() ? new BackLink($dashboardUrl, UptimePages::label('nav.dashboard')) : null),
            $form->createView(),
            UptimePages::label('monitor.form.save'),
            $dashboardUrl,
            UptimePages::label('monitor.form.cancel'),
            bundle: UptimePages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'uptime-monitor-form'],
            formThemes: $this->pages->formThemes(),
            formAttributes: ['class' => 'uptime-monitor-form'],
        );
    }

    /**
     * Edit of a monitor without {@see UptimeActions} (a controller built by hand): opens the monitor form dialog.
     */
    private function editAction(string $tenantSlug, Monitor $monitor): Action
    {
        $editUrl = $this->generateUrl('nowo_uptime_monitor_edit', ['tenantSlug' => $tenantSlug, 'id' => $monitor->getId()]);

        return new Action('edit', UptimePages::label('monitor.edit'), $editUrl, attributes: [
            'data-monitor-form-open'   => true,
            'data-monitor-form-url'    => $editUrl,
            'data-monitor-form-return' => 'show',
            'data-testid'              => 'uptime-monitor-edit',
        ]);
    }

    /**
     * Dispatches the host extension event of a page (returned as is without a dispatcher).
     */
    private function dispatchPageEvent(UptimePageEvent $event): UptimePageEvent
    {
        $this->eventDispatcher?->dispatch($event);

        return $event;
    }

    /**
     * User identifier of the current user for the lifecycle events; null without a security token.
     */
    private function actorId(): ?string
    {
        return $this->tokenStorage?->getToken()?->getUserIdentifier();
    }

    /**
     * The stored values of the write-only form fields (the password since 1.25, the URL, proxy and headers since
     * 1.25.1), compared in memory so a change is reported by name although the form only holds blanks or masks.
     *
     * @return array<string, mixed>
     */
    private function storedSecrets(Monitor $monitor): array
    {
        $config = $monitor->getConfig();

        return [
            'authPassword' => $config['auth_password'] ?? null,
            'url'          => $config['url'] ?? null,
            'proxy'        => $config['proxy'] ?? null,
            'httpHeaders'  => $config['headers'] ?? null,
        ];
    }

    /**
     * Names of the monitor form fields whose value differs (never the values: the form holds credentials).
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return list<string>
     */
    private function changedFields(array $before, array $after): array
    {
        $changed = [];
        foreach (array_keys($after) as $field) {
            if (($before[$field] ?? null) !== $after[$field]) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * Dispatches a host quota check (returned as is without a dispatcher: allowed).
     */
    private function dispatchQuotaCheck(MonitorQuotaCheckEvent $check): MonitorQuotaCheckEvent
    {
        $this->eventDispatcher?->dispatch($check);

        return $check;
    }

    /**
     * The monitor page regions (`web_ui.monitor_show`, since 1.21): chart mode and slot templates.
     *
     * @return array{charts: string, slots: array<string, string|null>}
     */
    private function monitorShowLayout(): array
    {
        $options = $this->webUi['monitor_show'] ?? [];
        $charts  = $options['charts'] ?? 'bundle';
        $slots   = $options['slots'] ?? [];

        return [
            'charts' => in_array($charts, ['host', 'none'], true) ? $charts : 'bundle',
            'slots'  => is_array($slots) ? $slots : [],
        ];
    }

    /**
     * Rows per page of the check log (`web_ui.per_page`).
     */
    private function perPage(): int
    {
        $perPage = $this->webUi['per_page'] ?? 20;

        return is_int($perPage) && $perPage > 0 ? $perPage : 20;
    }

    /**
     * Shortest check interval of the tenant's monitors (`limits`), 30 s without a quota service.
     */
    private function minIntervalSeconds(string $tenantSlug): int
    {
        return $this->quota?->minIntervalSeconds($tenantSlug) ?? 30;
    }

    private function dashboardUrl(string $tenantSlug): string
    {
        return $this->generateUrl('nowo_uptime_dashboard', ['tenantSlug' => $tenantSlug]);
    }

    private function requireTenant(string $tenantSlug): Tenant
    {
        $tenant = $this->tenantRepository->findOneBySlug($tenantSlug);
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException(sprintf('Tenant "%s" not found.', $tenantSlug));
        }

        return $tenant;
    }

    private function requireMonitor(string $tenantSlug, int $id): Monitor
    {
        $monitor = $this->monitorRepository->find($id);
        if ($monitor === null || $monitor->getTenant()->getSlug() !== $tenantSlug) {
            throw $this->createNotFoundException('Monitor not found.');
        }

        return $monitor;
    }

    /**
     * @return array<string, int>
     */
    private function buildGroupChoices(string $tenantSlug, ?Monitor $exclude = null): array
    {
        $choices = [];
        foreach ($this->monitorRepository->findGroupsByTenantSlug($tenantSlug) as $group) {
            // Loaded from the database: the id is set.
            $groupId = (int) $group->getId();
            if ($exclude instanceof Monitor && $exclude->getId() === $groupId) {
                continue;
            }
            $choices[$group->getName()] = $groupId;
        }

        return $choices;
    }

    private function isModalRequest(Request $request): bool
    {
        return $request->headers->get('X-Uptime-Modal') === '1';
    }

    private function modalReturnTarget(Request $request): string
    {
        $target = (string) $request->headers->get('X-Uptime-Return', '');

        return in_array($target, ['show', 'dashboard', 'reload'], true) ? $target : 'dashboard';
    }
}
