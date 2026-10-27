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
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Form\MonitorFormType;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateChartService;
use Nowo\UptimeMonitorBundle\Service\DashboardViewBuilder;
use Nowo\UptimeMonitorBundle\Service\MonitorFactory;
use Nowo\UptimeMonitorBundle\Ui\UptimePages;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;
use function sprintf;

use const JSON_THROW_ON_ERROR;

#[Route(path: '/{tenantSlug}/monitors', name: 'nowo_uptime_monitor_', requirements: ['tenantSlug' => '[a-z0-9\-]+'])]
final class MonitorController extends AbstractUptimeController
{
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
    ) {
        parent::__construct($translator);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(string $tenantSlug, int $id): Response
    {
        $monitor = $this->requireMonitor($tenantSlug, $id);
        $series  = $this->chartService->buildMonitorSeries($monitor, AggregatePeriod::Day, 30);
        $detail  = $this->dashboardViewBuilder->buildMonitorDetail($monitor);
        $latest  = $this->checkResultRepository->findLatestForMonitor($monitor);

        return $this->render($this->pages->template('monitor_show', '@NowoUptimeMonitorBundle/monitor/show.html.twig'), $this->pages->context([
            'page'                => $this->buildShowPage($tenantSlug, $monitor),
            'tenant_slug'         => $tenantSlug,
            'monitor'             => $monitor,
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
        ]));
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(string $tenantSlug, Request $request): Response
    {
        $tenant = $this->requireTenant($tenantSlug);
        $data   = new MonitorFormData();
        $form   = $this->createForm(MonitorFormType::class, $data, [
            'group_choices' => $this->buildGroupChoices($tenantSlug),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $monitor = $this->monitorFactory->createFromFormData($tenant, $data);
            $this->entityManager->persist($monitor);
            $this->entityManager->flush();

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

        if ($this->isModalRequest($request)) {
            $status = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

            return $this->render('@NowoUptimeMonitorBundle/monitor/_monitor_form_modal.html.twig', $this->pages->context([
                'tenant_slug' => $tenantSlug,
                'form'        => $form,
            ]), new Response('', $status));
        }

        return $this->render($this->pages->template('monitor_form', '@NowoUptimeMonitorBundle/monitor/form.html.twig'), $this->pages->context([
            'page'        => $this->buildFormPage($tenantSlug, $form, 'monitor.new'),
            'tenant_slug' => $tenantSlug,
            'form'        => $form,
            'title'       => 'monitor.new',
        ]));
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(string $tenantSlug, int $id, Request $request): Response
    {
        $monitor = $this->requireMonitor($tenantSlug, $id);
        $data    = $this->monitorFactory->toFormData($monitor);
        $form    = $this->createForm(MonitorFormType::class, $data, [
            'group_choices' => $this->buildGroupChoices($tenantSlug, $monitor),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->monitorFactory->applyFormData($monitor, $data);
            $this->entityManager->flush();

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

        if ($this->isModalRequest($request)) {
            $status = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

            return $this->render('@NowoUptimeMonitorBundle/monitor/_monitor_form_modal.html.twig', $this->pages->context([
                'tenant_slug' => $tenantSlug,
                'form'        => $form,
                'monitor'     => $monitor,
            ]), new Response('', $status));
        }

        return $this->render($this->pages->template('monitor_form', '@NowoUptimeMonitorBundle/monitor/form.html.twig'), $this->pages->context([
            'page'        => $this->buildFormPage($tenantSlug, $form, 'monitor.edit'),
            'tenant_slug' => $tenantSlug,
            'form'        => $form,
            'title'       => 'monitor.edit',
            'monitor'     => $monitor,
        ]));
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(string $tenantSlug, int $id, Request $request): RedirectResponse
    {
        $monitor = $this->requireMonitor($tenantSlug, $id);

        if ($this->isCsrfTokenValid('delete' . $monitor->getId(), (string) $request->request->get('_token'))) {
            $this->entityManager->remove($monitor);
            $this->entityManager->flush();
            $this->addFlash('success', $this->transMessage('flash.monitor.deleted'));
        }

        return $this->redirectToRoute('nowo_uptime_dashboard', ['tenantSlug' => $tenantSlug]);
    }

    #[Route('/{id}/toggle-pause', name: 'toggle_pause', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function togglePause(string $tenantSlug, int $id, Request $request): RedirectResponse
    {
        $monitor = $this->requireMonitor($tenantSlug, $id);

        if ($this->isCsrfTokenValid('toggle' . $monitor->getId(), (string) $request->request->get('_token'))) {
            // @igor-ignore - HTTP handler delegates to services; no controller worker state.
            $monitor->setPaused(!$monitor->isPaused());
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('nowo_uptime_dashboard', ['tenantSlug' => $tenantSlug]);
    }

    /**
     * Monitor detail view model: name, target, back link, Edit (opens the monitor form dialog; plain link without
     * JavaScript) and the details section.
     */
    private function buildShowPage(string $tenantSlug, Monitor $monitor): ShowPage
    {
        $editUrl = $this->generateUrl('nowo_uptime_monitor_edit', ['tenantSlug' => $tenantSlug, 'id' => $monitor->getId()]);
        $parent  = $monitor->getParent();

        return new ShowPage(
            new PageHeader(
                $monitor->getName(),
                $monitor->getTarget(),
                [
                    new Action('edit', UptimePages::label('monitor.edit'), $editUrl, attributes: [
                        'data-monitor-form-open'   => true,
                        'data-monitor-form-url'    => $editUrl,
                        'data-monitor-form-return' => 'show',
                        'data-testid'              => 'uptime-monitor-edit',
                    ]),
                ],
                back: new BackLink($this->dashboardUrl($tenantSlug), UptimePages::label('nav.dashboard')),
            ),
            [
                new DetailSection(UptimePages::label('monitor.show.details'), [
                    new DetailItem(UptimePages::label('monitor.show.type'), $monitor->getType()->value, badge: 'neutral'),
                    new DetailItem(
                        UptimePages::label($parent instanceof Monitor ? 'monitor.show.group' : 'monitor.show.project'),
                        $parent instanceof Monitor ? $parent->getName() : $monitor->getProject(),
                        $parent instanceof Monitor && $parent->getId() !== null ? $this->generateUrl('nowo_uptime_monitor_show', ['tenantSlug' => $tenantSlug, 'id' => $parent->getId()]) : null,
                    ),
                    new DetailItem(UptimePages::label('monitor.show.target'), $monitor->getTarget()),
                    new DetailItem(UptimePages::label('monitor.show.interval_label'), UptimePages::label('monitor.show.seconds', ['%seconds%' => $monitor->getIntervalSeconds()])),
                ], 'uptime-monitor-details'),
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
            new PageHeader(UptimePages::label($title), back: new BackLink($dashboardUrl, UptimePages::label('nav.dashboard'))),
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
