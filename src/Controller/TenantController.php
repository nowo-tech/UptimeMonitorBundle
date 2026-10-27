<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Form\TenantFormType;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Ui\UptimePages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function count;

/**
 * Tenant list and creation pages (`/tenants`).
 */
#[Route(path: '/tenants', name: 'nowo_uptime_tenant_', priority: 1)]
final class TenantController extends AbstractController
{
    /**
     * @param array<string, mixed> $tenantsConfig
     * @param array<string, mixed> $multiTenantConfig
     */
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%nowo_uptime_monitor.tenants%')]
        private readonly array $tenantsConfig,
        #[Autowire('%nowo_uptime_monitor.multi_tenant%')]
        private readonly array $multiTenantConfig,
        private readonly UptimePages $pages = new UptimePages(),
    ) {
    }

    /**
     * Lists the tenants, or redirects to a dashboard when the list is disabled or there is a single tenant to open.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $tenants = $this->tenantRepository->findBy([], ['name' => 'ASC']);

        if (!$this->isTenantListEnabled()) {
            return $this->redirectToDashboard($this->defaultTenantSlug($tenants));
        }

        if ($this->shouldRedirectWhenSingle($tenants)) {
            return $this->redirectToDashboard($tenants[0]->getSlug());
        }

        $page = new ListPage(
            new PageHeader(UptimePages::label('tenant.index.title'), null, [
                Action::create($this->generateUrl('nowo_uptime_tenant_new'), UptimePages::label('tenant.index.new')),
            ]),
            [
                new Column('name', UptimePages::label('tenant.col.name')),
                new Column('slug', UptimePages::label('tenant.col.slug')),
            ],
            $tenants,
            empty: new EmptyState(UptimePages::label('tenant.index.empty')),
            bundle: UptimePages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'uptime-tenants'],
        );

        return $this->render($this->pages->template('tenant_index', '@NowoUptimeMonitorBundle/tenant/index.html.twig'), $this->pages->context([
            'page'    => $page,
            'tenants' => $tenants,
        ]));
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = ['slug' => '', 'name' => ''];
        $form = $this->createForm(TenantFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{slug: string, name: string} $data */
            $data   = $form->getData();
            $tenant = new Tenant($data['slug'], $data['name']);
            $this->entityManager->persist($tenant);
            $this->entityManager->flush();

            return $this->redirectToRoute('nowo_uptime_dashboard', ['tenantSlug' => $tenant->getSlug()]);
        }

        $indexUrl = $this->generateUrl('nowo_uptime_tenant_index');
        $page     = new FormPage(
            new PageHeader(UptimePages::label('tenant.new.title'), back: new BackLink($indexUrl, UptimePages::label('tenant.back'))),
            $form->createView(),
            UptimePages::label('tenant.form.create'),
            $indexUrl,
            bundle: UptimePages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'uptime-tenant-form'],
            formThemes: $this->pages->formThemes(),
        );

        return $this->render($this->pages->template('tenant_form', '@NowoUptimeMonitorBundle/tenant/form.html.twig'), $this->pages->context([
            'page'  => $page,
            'form'  => $form,
            'title' => 'tenant.new.title',
        ]));
    }

    private function isTenantListEnabled(): bool
    {
        return (bool) ($this->tenantsConfig['list_enabled'] ?? true);
    }

    /**
     * @param list<Tenant> $tenants
     */
    private function shouldRedirectWhenSingle(array $tenants): bool
    {
        return (bool) ($this->tenantsConfig['redirect_when_single'] ?? false) && count($tenants) === 1;
    }

    /**
     * @param list<Tenant> $tenants
     */
    private function defaultTenantSlug(array $tenants): string
    {
        $configured = (string) ($this->multiTenantConfig['default_tenant'] ?? 'main');
        foreach ($tenants as $tenant) {
            if ($tenant->getSlug() === $configured) {
                return $configured;
            }
        }

        return $tenants[0]->getSlug();
    }

    private function redirectToDashboard(string $tenantSlug): RedirectResponse
    {
        return $this->redirectToRoute('nowo_uptime_dashboard', ['tenantSlug' => $tenantSlug]);
    }
}
