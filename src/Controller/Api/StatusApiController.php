<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Controller\Api;

use DateTimeImmutable;
use Exception;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\SummaryPayloadBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

use function is_string;

/**
 * JSON API for dashboard polling (monitor list + last check status).
 */
#[AsController]
final class StatusApiController extends AbstractController
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly SummaryPayloadBuilder $payloadBuilder,
    ) {
    }

    #[Route(
        path: '/api/uptime/{tenantSlug}/summary',
        name: 'nowo_uptime_api_summary',
        methods: ['GET'],
    )]
    public function summary(string $tenantSlug, Request $request): JsonResponse
    {
        $tenant = $this->tenantRepository->findOneBySlug($tenantSlug);
        if ($tenant === null) {
            return $this->json(['error' => 'Tenant not found'], Response::HTTP_NOT_FOUND);
        }

        $since = $this->parseSince($request->query->get('since'));

        return $this->json($this->payloadBuilder->buildTenantSummary($tenantSlug, $since));
    }

    private function parseSince(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
