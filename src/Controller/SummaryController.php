<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Controller;

use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\DashboardPage;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Monitor\TenantSettings;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\StatusSummary;
use Nowo\UptimeMonitorBundle\Ui\UptimePages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function sprintf;

/**
 * The status of a tenant at a glance for its users (since 1.13): how many active monitors are operational, the others
 * by status, each monitor's latest check (linking to its page) and the way to the public status page when it is
 * public — the status page inside the admin UI.
 */
final class SummaryController extends AbstractController
{
    /**
     * Creates the summary controller.
     *
     * @param array<string, mixed> $statusPageConfig Processed `status_page` config
     */
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly StatusSummary $summary,
        #[Autowire('%nowo_uptime_monitor.status_page%')]
        private readonly array $statusPageConfig = [],
        private readonly UptimePages $pages = new UptimePages(),
    ) {
    }

    /**
     * Renders the tenant status summary.
     *
     * @param string $tenantSlug Tenant slug from the route
     *
     * @throws NotFoundHttpException When the tenant does not exist
     */
    #[Route(
        path: '/{tenantSlug}/summary',
        name: 'nowo_uptime_summary',
        requirements: ['tenantSlug' => '(?!tenants$)[a-z0-9\-]+'],
    )]
    public function index(string $tenantSlug): Response
    {
        $tenant = $this->tenantRepository->findOneBySlug($tenantSlug);
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException(sprintf('Tenant "%s" not found.', $tenantSlug));
        }

        $rows      = $this->summary->rows($tenantSlug);
        $publicUrl = $this->publicUrl($tenant);

        return $this->render($this->pages->template('summary', '@NowoUptimeMonitorBundle/summary/index.html.twig'), $this->pages->context([
            'page' => new DashboardPage(
                new PageHeader(
                    UptimePages::label('summary.title'),
                    null,
                    $publicUrl === null ? [] : [new Action('view', UptimePages::label('summary.public_page'), $publicUrl, attributes: ['target' => '_blank', 'rel' => 'noopener', 'data-testid' => 'uptime-summary-public'])],
                ),
                bundle: UptimePages::BUNDLE,
                layout: $this->pages->layout(),
                attributes: ['data-testid' => 'uptime-summary'],
            ),
            'tenant'         => $tenant,
            'tenant_slug'    => $tenantSlug,
            'rows'           => $rows,
            'counts'         => StatusSummary::counts($rows),
            'overall_status' => StatusSummary::overall($rows),
            'public_url'     => $publicUrl,
            'summary_empty'  => new EmptyState(
                UptimePages::label('summary.empty_title'),
                UptimePages::label('summary.empty'),
                Action::view($this->generateUrl('nowo_uptime_dashboard', ['tenantSlug' => $tenantSlug]), UptimePages::label('summary.go_monitors')),
            ),
        ]));
    }

    /**
     * The public status page of the tenant when it is enabled and public, else null.
     */
    private function publicUrl(Tenant $tenant): ?string
    {
        if (!($this->statusPageConfig['enabled'] ?? true) || !TenantSettings::from($tenant)->isStatusPagePublic((bool) ($this->statusPageConfig['default_public'] ?? true))) {
            return null;
        }

        return $this->generateUrl('nowo_uptime_status_page', ['tenantSlug' => $tenant->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
