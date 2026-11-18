<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\EventSubscriber;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException as DriverExceptionWrapper;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\UptimeMonitorBundle\Controller\PushController;
use Nowo\UptimeMonitorBundle\EventSubscriber\StorageFailureSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

#[CoversClass(StorageFailureSubscriber::class)]
final class StorageFailureSubscriberTest extends TestCase
{
    public function testItListensAfterTheApplicationAndBeforeTheErrorListener(): void
    {
        self::assertSame([KernelEvents::EXCEPTION => ['onKernelException', -64]], StorageFailureSubscriber::getSubscribedEvents());
    }

    public function testATransientFailureOfAPageIsA409WithItsRetryAfterAndCode001(): void
    {
        $failure = new TransientStorageFailure(3);
        $event   = $this->event($this->request('nowo_uptime_monitor_edit', 'Nowo\UptimeMonitorBundle\Controller\MonitorController::edit'), $failure);

        (new StorageFailureSubscriber())->onKernelException($event);

        $mapped = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $mapped);
        self::assertSame(409, $mapped->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '3'], $mapped->getHeaders());
        self::assertSame($failure, $mapped->getPrevious());
        self::assertStringNotContainsString('SQL', $mapped->getMessage());
    }

    public function testARawDeadlockOfAJsonRouteIsA409BodyWithTheCode(): void
    {
        $event = $this->event($this->request('nowo_uptime_push', PushController::class), new DeadlockException($this->createStub(DriverException::class), null));

        (new StorageFailureSubscriber())->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('{"error":"unavailable","code":"CONFLICT_001"}', $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testASerializationFailureIsAConflict(): void
    {
        $event = $this->event($this->request('nowo_uptime_push', PushController::class), new DriverExceptionWrapper($this->driverError('serialization', '40001', 0), null));

        (new StorageFailureSubscriber())->onKernelException($event);

        self::assertSame(409, $event->getResponse()?->getStatusCode());
    }

    public function testATransientFailureWithoutAHintWaitsThePolicysRetryAfterOnTheApi(): void
    {
        // A renamed tenant-path route of an API controller is still the bundle's.
        $event = $this->event($this->request('host_status', 'Nowo\UptimeMonitorBundle\Controller\Api\StatusApiController::status'), new TransientStorageFailure());

        (new StorageFailureSubscriber(new ConflictResponsePolicy(retryAfterSeconds: 4)))->onKernelException($event);

        self::assertSame('4', $event->getResponse()?->headers->get('Retry-After'));
    }

    public function testTheStatusIsConfigurableTo503(): void
    {
        $page = $this->event($this->request('nowo_uptime_monitor_edit', null), new TransientStorageFailure(2));
        $json = $this->event($this->request('nowo_uptime_push', null), new TransientStorageFailure(2));

        $subscriber = new StorageFailureSubscriber(new ConflictResponsePolicy(503));
        $subscriber->onKernelException($page);
        $subscriber->onKernelException($json);

        self::assertInstanceOf(HttpException::class, $page->getThrowable());
        self::assertSame(503, $page->getThrowable()->getStatusCode());
        self::assertSame(503, $json->getResponse()?->getStatusCode());
        self::assertSame('{"error":"unavailable","code":"CONFLICT_001"}', $json->getResponse()->getContent());
    }

    public function testAPartlyCommittedConflictIsCode002WithoutRetryAfter(): void
    {
        $committed = new ConcurrencyConflict(2, TransientFailureKind::Deadlock, 1, new DeadlockException($this->createStub(DriverException::class), null), true);
        $json      = $this->event($this->request('nowo_uptime_push', null), $committed);
        $page      = $this->event($this->request('nowo_uptime_monitor_edit', null), $committed);

        (new StorageFailureSubscriber())->onKernelException($json);
        (new StorageFailureSubscriber())->onKernelException($page);

        $response = $json->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertFalse($response->headers->has('Retry-After'));
        self::assertSame('{"error":"conflict","code":"CONFLICT_002"}', $response->getContent());
        $mapped = $page->getThrowable();
        self::assertInstanceOf(HttpException::class, $mapped);
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_002'], $mapped->getHeaders());
        self::assertStringContainsString('Reload', $mapped->getMessage());
    }

    public function testALostSavepointWithNoDeadlockIsCode002(): void
    {
        $lost  = new DriverExceptionWrapper($this->driverError('SAVEPOINT DOCTRINE_2 does not exist', '42000', 1305), null);
        $event = $this->event($this->request('nowo_uptime_push', null), new TransientStorageFailure(1, 'lost', $lost));

        (new StorageFailureSubscriber())->onKernelException($event);

        self::assertSame('CONFLICT_002', $event->getResponse()?->headers->get('X-Conflict-Code'));
        self::assertFalse($event->getResponse()->headers->has('Retry-After'));
    }

    public function testUniqueViolationsAndLostConnectionsAreNotConflicts(): void
    {
        $unique = new UniqueConstraintViolationException($this->createStub(DriverException::class), null);
        $lost   = new ConnectionLost($this->createStub(DriverException::class), null);
        foreach ([$unique, $lost, new TransientStorageFailure(1, 'wrapped', $lost)] as $failure) {
            $event = $this->event($this->request('nowo_uptime_tenant_new', null, 'application/json'), $failure);

            (new StorageFailureSubscriber())->onKernelException($event);

            self::assertNull($event->getResponse());
            self::assertSame($failure, $event->getThrowable());
        }
    }

    public function testOtherFailuresAndOtherRoutesAreLeftAlone(): void
    {
        $other     = new RuntimeException('boom');
        $ours      = $this->event($this->request('nowo_uptime_dashboard', null), $other);
        $foreign   = $this->event($this->request('app_home', 'App\Controller\HomeController'), new TransientStorageFailure(1));
        $anonymous = $this->event(new Request(), new TransientStorageFailure(1));

        foreach ([$ours, $foreign, $anonymous] as $event) {
            (new StorageFailureSubscriber())->onKernelException($event);
            self::assertNull($event->getResponse());
        }
        self::assertSame($other, $ours->getThrowable());
        self::assertInstanceOf(TransientStorageFailure::class, $foreign->getThrowable());
    }

    private function driverError(string $message, string $sqlState, int $code): DriverException
    {
        return new class($message, $sqlState, $code) extends AbstractException {
        };
    }

    private function request(string $route, ?string $controller, ?string $accept = null): Request
    {
        $request = new Request();
        $request->attributes->set('_route', $route);
        if ($controller !== null) {
            $request->attributes->set('_controller', $controller);
        }
        if ($accept !== null) {
            $request->headers->set('Accept', $accept);
        }

        return $request;
    }

    private function event(Request $request, Throwable $failure): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
    }
}
