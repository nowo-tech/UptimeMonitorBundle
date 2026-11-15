<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\EventSubscriber;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TransactionContract\TransientStorageFailure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function is_string;
use function max;
use function str_starts_with;

/**
 * 1.29 (Beacon spec 239): a storage failure of a bundle route answers 503 or 409 instead of 500.
 *
 * - transient ({@see TransientStorageFailure} of the bundle's runner, or a DBAL {@see RetryableException}: deadlock,
 *   lock wait timeout): `503 Service Unavailable` with `Retry-After` (the runner's hint, else 1 s);
 * - conflict (a unique key refused the write of a concurrent request: a tenant slug, a tag name, a push URL key):
 *   `409 Conflict`.
 *
 * The push endpoint and the JSON API answer `{"error": "unavailable" | "conflict"}` (no-store); the HTML pages get a
 * {@see ServiceUnavailableHttpException} / {@see ConflictHttpException}, rendered by the application's error page. The
 * storage error stays the previous exception (for the logs): no SQL or parameter reaches the client.
 *
 * Only the bundle's routes: a `nowo_uptime_*` route or a controller of the bundle (tenant paths may rename routes).
 * Priority -64: after the application's own exception listeners, before the framework's error listener (-128).
 */
final class StorageFailureSubscriber implements EventSubscriberInterface
{
    /** Route name prefix of the bundle. */
    private const ROUTE_PREFIX = 'nowo_uptime_';

    /** Controller namespace of the bundle. */
    private const CONTROLLER_PREFIX = 'Nowo\\UptimeMonitorBundle\\Controller\\';

    /** Controllers that answer JSON. */
    private const JSON_CONTROLLERS = [self::CONTROLLER_PREFIX . 'Api\\', self::CONTROLLER_PREFIX . 'PushController'];

    /** Retry-After when the failure carries no hint. */
    private const DEFAULT_RETRY_AFTER = 1;

    /**
     * Listens on kernel.exception at -64.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', -64],
        ];
    }

    /**
     * Maps a transient storage failure to 503 and a unique-key conflict to 409 on the bundle's routes.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->isBundleRequest($request)) {
            return;
        }

        $failure = $event->getThrowable();
        $retry   = $this->retryAfter($failure);
        if ($retry !== null) {
            if ($this->wantsJson($request)) {
                $event->setResponse($this->json('unavailable', Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => (string) $retry]));

                return;
            }
            $event->setThrowable(new ServiceUnavailableHttpException($retry, 'The service is temporarily unavailable. Retry later.', $failure));

            return;
        }

        if ($failure instanceof UniqueConstraintViolationException) {
            if ($this->wantsJson($request)) {
                $event->setResponse($this->json('conflict', Response::HTTP_CONFLICT));

                return;
            }
            $event->setThrowable(new ConflictHttpException('A concurrent request changed the same data. Reload and retry.', $failure));
        }
    }

    /**
     * The Retry-After seconds of a transient failure; null when the failure is not transient.
     */
    private function retryAfter(Throwable $failure): ?int
    {
        if ($failure instanceof TransientStorageFailure) {
            return max(1, $failure->retryAfterSeconds() ?? self::DEFAULT_RETRY_AFTER);
        }

        return $failure instanceof RetryableException ? self::DEFAULT_RETRY_AFTER : null;
    }

    private function isBundleRequest(Request $request): bool
    {
        $route = $request->attributes->get('_route');
        if (is_string($route) && str_starts_with($route, self::ROUTE_PREFIX)) {
            return true;
        }
        $controller = $request->attributes->get('_controller');

        return is_string($controller) && str_starts_with($controller, self::CONTROLLER_PREFIX);
    }

    private function wantsJson(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');
        if (is_string($controller)) {
            foreach (self::JSON_CONTROLLERS as $prefix) {
                if (str_starts_with($controller, $prefix)) {
                    return true;
                }
            }
        }

        return $request->attributes->get('_route') === 'nowo_uptime_push' || $request->getPreferredFormat() === 'json';
    }

    /**
     * @param array<string, string> $headers
     */
    private function json(string $error, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => $error], $status, $headers + ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
