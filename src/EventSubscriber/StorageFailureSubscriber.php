<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\EventSubscriber;

use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\ConstraintViolationException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\Conflict\TransientFailureConflictMapper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function is_string;
use function str_starts_with;

/**
 * A lock conflict of a bundle route answers the platform's conflict answer (REQ-RUNTIME-003 rule 6) instead of a 500.
 *
 * A lock conflict is a deadlock, a lock wait timeout or a serialization failure: the bundle runner's
 * {@see TransientStorageFailure}, or one no runner wrapped
 * ({@see TransientFailureConflictMapper}). Since 1.31:
 *
 * - nothing was committed: **409** + `Retry-After` (the failure's hint, else `conflict_response.retry_after`) and code
 *   `CONFLICT_001`; the client may retry;
 * - part may already be committed (a lost savepoint with no deadlock behind it, or a write unit that committed): **409**
 *   without `Retry-After` and code `CONFLICT_002`; the client reads again before repeating anything.
 *
 * The status comes from {@see ConflictResponsePolicy}: `nowo_uptime_monitor.conflict_response.status` (409, or 503
 * for hosts that keep the 1.29 – 1.30 answer), else the shared `nowo_transaction_doctrine.conflict_response` policy.
 * Unique-constraint violations and lost connections are never conflicts: the bundle catches its expected "only one"
 * races where they happen (tenant slug, tag name: a form error), anything else stays a real error.
 *
 * The push endpoint and the JSON API answer `{"error": "unavailable", "code": "CONFLICT_001"}` (`"conflict"` with
 * `CONFLICT_002`; no-store); the HTML pages get an {@see HttpException} with the status, the headers and
 * `X-Conflict-Code`, rendered by the application's error page. The storage error stays the previous exception (for the
 * logs): no SQL or parameter reaches the client.
 *
 * Only the bundle's routes: a `nowo_uptime_*` route or a controller of the bundle (tenant paths may rename routes).
 * Priority -64: after the application's own exception listeners, before the framework's error listener (-128).
 * Stateless: one instance serves every request of a long-running worker.
 */
final readonly class StorageFailureSubscriber implements EventSubscriberInterface
{
    /** Header that carries the conflict code on every answer (HTML pages included). */
    public const CODE_HEADER = 'X-Conflict-Code';

    /** Route name prefix of the bundle. */
    private const ROUTE_PREFIX = 'nowo_uptime_';

    /** Controller namespace of the bundle. */
    private const CONTROLLER_PREFIX = 'Nowo\\UptimeMonitorBundle\\Controller\\';

    /** Controllers that answer JSON. */
    private const JSON_CONTROLLERS = [self::CONTROLLER_PREFIX . 'Api\\', self::CONTROLLER_PREFIX . 'PushController'];

    private TransientFailureConflictMapper $mapper;

    /**
     * @param ConflictResponsePolicy $policy Status (409 or 503) and default `Retry-After` of the conflict answer
     */
    public function __construct(
        private ConflictResponsePolicy $policy = new ConflictResponsePolicy(),
    ) {
        $this->mapper = new TransientFailureConflictMapper();
    }

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
     * Answers a lock conflict of the bundle's routes with the policy's status and a CONFLICT code.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->isBundleRequest($request)) {
            return;
        }

        $failure = $event->getThrowable();
        if (!$this->isConflict($failure)) {
            return;
        }

        $code    = ConflictCode::forFailure($failure);
        $headers = [self::CODE_HEADER => $code->value];
        if ($code->retrySafe()) {
            $headers += $this->policy->headers($this->hint($failure));
        }

        if ($this->wantsJson($request)) {
            $event->setResponse($this->json($code, $headers));

            return;
        }

        $message = $code->retrySafe()
            ? 'A concurrent request held the same data. Retry later.'
            : 'A concurrent request changed the same data and part of this one may be saved. Reload before trying again.';
        $event->setThrowable(new HttpException($this->policy->status, $message, $failure, $headers));
    }

    /**
     * Whether the failure is a lock conflict: never a constraint violation or a lost connection, even when a runner
     * wrapped it.
     */
    private function isConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof ConstraintViolationException || $current instanceof ConnectionException) {
                return false;
            }
        }

        return $this->mapper->isConflict($failure);
    }

    /**
     * The failure's own Retry-After hint (the runner's); null = the policy's value.
     */
    private function hint(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return $current->retryAfterSeconds();
            }
        }

        return null;
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
     * The JSON answer: the 1.29 `error` key (`unavailable` when a retry is safe, `conflict` otherwise) plus `code`.
     *
     * @param array<string, string> $headers
     */
    private function json(ConflictCode $code, array $headers): JsonResponse
    {
        return new JsonResponse(
            ['error' => $code->retrySafe() ? 'unavailable' : 'conflict', 'code' => $code->value],
            $this->policy->status,
            $headers + ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
