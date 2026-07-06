<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Form\TenantFormType;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
    ) {
    }

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

        return $this->render('@NowoUptimeMonitorBundle/tenant/index.html.twig', [
            'tenants' => $tenants,
        ]);
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

        return $this->render('@NowoUptimeMonitorBundle/tenant/form.html.twig', [
            'form'  => $form,
            'title' => 'New tenant',
        ]);
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
        return (bool) ($this->tenantsConfig['redirect_when_single'] ?? false) && \count($tenants) === 1;
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

        return $tenants[0]->getSlug() ?? $configured;
    }

    private function redirectToDashboard(string $tenantSlug): Response
    {
        return $this->redirectToRoute('nowo_uptime_dashboard', ['tenantSlug' => $tenantSlug]);
    }
}
