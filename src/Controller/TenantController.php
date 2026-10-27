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
use Nowo\UptimeMonitorBundle\Security\TenantAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\UptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Ui\UptimePages;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function array_filter;
use function array_values;
use function count;
use function is_object;

/**
 * Tenant list and creation pages (`/tenants`).
 */
#[Route(path: '/tenants', name: 'nowo_uptime_tenant_', priority: 1)]
final class TenantController extends AbstractController
{
    /**
     * @param array<string, mixed> $tenantsConfig
     * @param array<string, mixed> $multiTenantConfig
     * @param UptimeMonitorAccessCheckerInterface|null $accessChecker Configured checker; a tenant-aware one filters the list
     * @param TokenStorageInterface|null $tokenStorage Current security token (null without SecurityBundle)
     */
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%nowo_uptime_monitor.tenants%')]
        private readonly array $tenantsConfig,
        #[Autowire('%nowo_uptime_monitor.multi_tenant%')]
        private readonly array $multiTenantConfig,
        private readonly UptimePages $pages = new UptimePages(),
        private readonly ?UptimeMonitorAccessCheckerInterface $accessChecker = null,
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    /**
     * Lists the tenants, or redirects to a dashboard when the list is disabled or there is a single tenant to open.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $tenants = $this->visibleTenants($this->tenantRepository->findBy([], ['name' => 'ASC']));

        if (!$this->isTenantListEnabled()) {
            if ($tenants === []) {
                throw $this->createAccessDeniedException('No tenant available to the current user.');
            }

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

    /**
     * Shows and saves the new-tenant form; redirects to the new tenant's dashboard on success.
     *
     * @param Request $request Current request (form submission)
     */
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

    /**
     * Keeps the tenants the user may open when the checker is tenant-aware (SEC-004 L6); all of them otherwise.
     *
     * @param list<Tenant> $tenants
     *
     * @return list<Tenant>
     */
    private function visibleTenants(array $tenants): array
    {
        $checker = $this->accessChecker;
        if (!$checker instanceof TenantAwareUptimeMonitorAccessCheckerInterface) {
            return $tenants;
        }

        $user = $this->tokenStorage?->getToken()?->getUser();
        $user = is_object($user) ? $user : new stdClass();

        return array_values(array_filter(
            $tenants,
            static fn (Tenant $tenant): bool => $checker->canAccessTenant($user, $tenant->getSlug()),
        ));
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
