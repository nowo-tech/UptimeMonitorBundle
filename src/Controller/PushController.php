<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Push\PushCredentials;
use Nowo\UptimeMonitorBundle\Push\PushPayload;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

use function fclose;
use function intdiv;
use function is_resource;
use function is_string;
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
 */
#[AsController]
final readonly class PushController
{
    /**
     * @param array{enabled: bool, max_body_bytes: int, signature_window_seconds: int, min_push_seconds: int} $push `push` configuration
     */
    public function __construct(
        private MonitorRepository $monitorRepository,
        private PushCredentials $credentials,
        private EntityManagerInterface $entityManager,
        #[Autowire('%nowo_uptime_monitor.push%')]
        private array $push,
        private ClockInterface $clock = new NativeClock(),
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

        $now   = $this->clock->now();
        $last  = $monitor->getPushLastAt();
        $every = max($this->push['min_push_seconds'], intdiv($monitor->getIntervalSeconds(), 2));
        if ($last instanceof DateTimeImmutable && $now->getTimestamp() - $last->getTimestamp() < $every) {
            return $this->reply(['error' => 'too_many_pushes'], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) max(1, $every - ($now->getTimestamp() - $last->getTimestamp())),
            ]);
        }
        if (trim($body) !== '' && !str_starts_with(strtolower((string) $request->headers->get('Content-Type', '')), 'application/json')) {
            return $this->reply(['error' => 'unsupported_media_type'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        [$sample, $errors] = PushPayload::parse(trim($body));
        if ($sample === null) {
            return $this->reply(['error' => 'invalid_payload', 'details' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $monitor->recordPush($now, $sample);
        $this->entityManager->flush();

        return $this->reply(['status' => 'accepted'], Response::HTTP_ACCEPTED);
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
        if (!is_resource($stream)) {
            return '';
        }
        $body = stream_get_contents($stream, $max + 1);
        fclose($stream);
        if (!is_string($body)) {
            return '';
        }

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
