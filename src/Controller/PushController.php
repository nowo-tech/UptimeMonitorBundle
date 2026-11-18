<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Push\PushCredentials;
use Nowo\UptimeMonitorBundle\Push\PushPayload;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Transaction\UptimeTransactionRunner;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

use function intdiv;
use function is_int;
use function is_resource;
use function max;
use function preg_match;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function strtolower;
use function trim;

/**
 * Ingest of push monitors (since 1.14): `POST <push.path>/{key}` with `Authorization: Bearer <token>` and a JSON
 * body of metrics ({@see PushPayload}).
 *
 * No session, cookie or CSRF: the token is the only credential and names one monitor; the tenant is the monitor's,
 * never taken from the request. An unknown key, a wrong token, a revoked token and a bad signature give the same 401;
 * a paused monitor or a disabled tenant (known only once authenticated) 403; nothing is stored unless the push is
 * accepted (202).
 *
 * Since 1.29 (Beacon spec 239) the push is stored in one unit of work of the bundle's runner that locks the monitor row
 * and checks the pause and the push rate again on the committed state: two concurrent pushes of one monitor never both
 * pass the rate limit, and a push never overwrites the check state written meanwhile. A deadlock or lock wait is retried
 * a few times, then answered `409` + `Retry-After` + `CONFLICT_001` (since 1.31; 503 before).
 */
final readonly class PushController
{
    /**
     * @param array{enabled: bool, max_body_bytes: int, signature_window_seconds: int, min_push_seconds: int} $push `push` configuration
     * @param UptimeTransactionRunner|null $transactionRunner The bundle's runner (since 1.29; null = one on $entityManager)
     */
    public function __construct(
        private MonitorRepository $monitorRepository,
        private PushCredentials $credentials,
        private EntityManagerInterface $entityManager,
        #[Autowire('%nowo_uptime_monitor.push%')]
        private array $push,
        private ClockInterface $clock = new NativeClock(),
        private ?UptimeTransactionRunner $transactionRunner = null,
    ) {
    }

    /**
     * Accepts one push of a monitor's health.
     *
     * @param string $key Opaque key of the push monitor (route)
     * @param Request $request The push (bearer token, optional signature, JSON body)
     */
    #[Route(path: '/{key}', name: 'nowo_uptime_push', requirements: ['key' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function __invoke(string $key, Request $request): JsonResponse
    {
        if (!$this->push['enabled']) {
            throw new NotFoundHttpException();
        }
        $max = $this->push['max_body_bytes'];
        if ((int) $request->headers->get('Content-Length', '0') > $max) {
            return $this->reply(['error' => 'payload_too_large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $body = $this->readBody($request, $max);
        if ($body === null) {
            return $this->reply(['error' => 'payload_too_large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $monitor = $this->monitorRepository->findOneByPushKey($key);
        $token   = $this->bearer($request);
        if (!$this->credentials->verifyToken($monitor, $token) || !$monitor instanceof Monitor || !$this->signatureAccepted($monitor, $request, $body)) {
            return $this->reply(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']);
        }
        if ($monitor->isPaused() || !$monitor->getTenant()->isEnabled()) {
            return $this->reply(['error' => 'monitor_disabled'], Response::HTTP_FORBIDDEN);
        }

        $now  = $this->clock->now();
        $wait = $this->waitSeconds($monitor, $now);
        if ($wait !== null) {
            return $this->tooManyPushes($wait);
        }
        if (trim($body) !== '' && !str_starts_with(strtolower((string) $request->headers->get('Content-Type', '')), 'application/json')) {
            return $this->reply(['error' => 'unsupported_media_type'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        [$sample, $errors] = PushPayload::parse(trim($body));
        if ($sample === null) {
            return $this->reply(['error' => 'invalid_payload', 'details' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $id      = (int) $monitor->getId();
        $outcome = ($this->transactionRunner ?? new UptimeTransactionRunner($this->entityManager))->runRetrying(function () use ($id, $now, $sample): int|string|null {
            // The committed state, locked until the commit: pause and rate are judged again on it.
            $locked = $this->monitorRepository->lockForWrite($id);
            if (!$locked instanceof Monitor) {
                return 'unauthorized';
            }
            if ($locked->isPaused() || !$locked->getTenant()->isEnabled()) {
                return 'monitor_disabled';
            }
            $wait = $this->waitSeconds($locked, $now);
            if ($wait !== null) {
                return $wait;
            }
            $locked->recordPush($now, $sample);

            return null;
        });

        return match (true) {
            $outcome === 'unauthorized'     => $this->reply(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']),
            $outcome === 'monitor_disabled' => $this->reply(['error' => 'monitor_disabled'], Response::HTTP_FORBIDDEN),
            is_int($outcome)                => $this->tooManyPushes($outcome),
            default                         => $this->reply(['status' => 'accepted'], Response::HTTP_ACCEPTED),
        };
    }

    /**
     * Seconds the monitor must wait before its next push; null when it may push now.
     */
    private function waitSeconds(Monitor $monitor, DateTimeImmutable $now): ?int
    {
        $last  = $monitor->getPushLastAt();
        $every = max($this->push['min_push_seconds'], intdiv($monitor->getIntervalSeconds(), 2));
        if (!$last instanceof DateTimeImmutable || $now->getTimestamp() - $last->getTimestamp() >= $every) {
            return null;
        }

        return max(1, $every - ($now->getTimestamp() - $last->getTimestamp()));
    }

    private function tooManyPushes(int $wait): JsonResponse
    {
        return $this->reply(['error' => 'too_many_pushes'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $wait]);
    }

    /**
     * A monitor that signs (config `push_signed`) needs a valid, fresh `X-Uptime-Signature`.
     */
    private function signatureAccepted(Monitor $monitor, Request $request, string $body): bool
    {
        if (($monitor->getConfig()['push_signed'] ?? false) !== true) {
            return true;
        }

        return $this->credentials->verifySignature(
            $monitor,
            $body,
            (string) $request->headers->get('X-Uptime-Timestamp', ''),
            (string) $request->headers->get('X-Uptime-Signature', ''),
            $this->clock->now()->getTimestamp(),
            $this->push['signature_window_seconds'],
        );
    }

    private function bearer(Request $request): string
    {
        $header = (string) $request->headers->get('Authorization', '');

        return preg_match('/^Bearer\s+(\S{1,512})$/i', $header, $match) === 1 ? $match[1] : '';
    }

    /**
     * The body, read up to the limit (null when it is longer: the declared length is not trusted).
     */
    private function readBody(Request $request, int $max): ?string
    {
        $stream = $request->getContent(true);
        $body   = is_resource($stream) ? (string) stream_get_contents($stream, $max + 1) : (string) $stream;

        return strlen($body) > $max ? null : $body;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    private function reply(array $data, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers + ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
