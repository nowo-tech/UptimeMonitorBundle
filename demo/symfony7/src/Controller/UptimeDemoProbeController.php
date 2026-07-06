<?php

declare(strict_types=1);

namespace App\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Local probe endpoints for demo monitors (flaky success/failure cycles).
 */
#[Route('/demo/uptime')]
final class UptimeDemoProbeController extends AbstractController
{
    private const HITS_KEY = 'uptime_demo_flaky_hits';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    #[Route('/ok', name: 'demo_uptime_ok', methods: ['GET', 'HEAD'])]
    public function ok(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'time'   => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/flaky/{cycle}', name: 'demo_uptime_flaky', requirements: ['cycle' => '\d+'], methods: ['GET', 'HEAD'])]
    public function flaky(int $cycle): Response
    {
        $cycle = max(2, $cycle);
        $item  = $this->cache->getItem(self::HITS_KEY);
        $hits  = ($item->isHit() ? (int) $item->get() : 0) + 1;
        $item->set($hits);
        $item->expiresAfter(86_400);
        $this->cache->save($item);

        if ($hits % $cycle === 0) {
            return new JsonResponse(
                ['status' => 'error', 'hit' => $hits, 'fails_every' => $cycle],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return new JsonResponse([
            'status'      => 'ok',
            'hit'         => $hits,
            'fails_every' => $cycle,
        ]);
    }
}
