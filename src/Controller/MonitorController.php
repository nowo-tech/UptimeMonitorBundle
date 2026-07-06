<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Form\MonitorFormType;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateChartService;
use Nowo\UptimeMonitorBundle\Service\DashboardViewBuilder;
use Nowo\UptimeMonitorBundle\Service\MonitorFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

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

        return $this->render('@NowoUptimeMonitorBundle/monitor/show.html.twig', [
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
        ]);
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

            return $this->render('@NowoUptimeMonitorBundle/monitor/_monitor_form_modal.html.twig', [
                'tenant_slug' => $tenantSlug,
                'form'        => $form,
            ], new Response('', $status));
        }

        return $this->render('@NowoUptimeMonitorBundle/monitor/form.html.twig', [
            'tenant_slug' => $tenantSlug,
            'form'        => $form,
            'title'       => 'monitor.new',
        ]);
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

            return $this->render('@NowoUptimeMonitorBundle/monitor/_monitor_form_modal.html.twig', [
                'tenant_slug' => $tenantSlug,
                'form'        => $form,
                'monitor'     => $monitor,
            ], new Response('', $status));
        }

        return $this->render('@NowoUptimeMonitorBundle/monitor/form.html.twig', [
            'tenant_slug' => $tenantSlug,
            'form'        => $form,
            'title'       => 'monitor.edit',
            'monitor'     => $monitor,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(string $tenantSlug, int $id, Request $request): Response
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
    public function togglePause(string $tenantSlug, int $id, Request $request): Response
    {
        $monitor = $this->requireMonitor($tenantSlug, $id);

        if ($this->isCsrfTokenValid('toggle' . $monitor->getId(), (string) $request->request->get('_token'))) {
            $monitor->setPaused(!$monitor->isPaused());
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('nowo_uptime_dashboard', ['tenantSlug' => $tenantSlug]);
    }

    private function requireTenant(string $tenantSlug): \Nowo\UptimeMonitorBundle\Entity\Tenant
    {
        $tenant = $this->tenantRepository->findOneBySlug($tenantSlug);
        if ($tenant === null) {
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
            $groupId = $group->getId();
            if ($groupId === null) {
                continue;
            }
            if ($exclude !== null && $exclude->getId() === $groupId) {
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

        return \in_array($target, ['show', 'dashboard', 'reload'], true) ? $target : 'dashboard';
    }
}
