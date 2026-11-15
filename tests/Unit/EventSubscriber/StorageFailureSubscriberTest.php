<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\EventSubscriber;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\UptimeMonitorBundle\Controller\PushController;
use Nowo\UptimeMonitorBundle\EventSubscriber\StorageFailureSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
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

    public function testATransientFailureOfAPageIsA503WithItsRetryAfter(): void
    {
        $failure = new TransientStorageFailure(3);
        $event   = $this->event($this->request('nowo_uptime_monitor_edit', 'Nowo\UptimeMonitorBundle\Controller\MonitorController::edit'), $failure);

        (new StorageFailureSubscriber())->onKernelException($event);

        $mapped = $event->getThrowable();
        self::assertInstanceOf(ServiceUnavailableHttpException::class, $mapped);
        self::assertSame(['Retry-After' => 3], $mapped->getHeaders());
        self::assertSame($failure, $mapped->getPrevious());
        self::assertStringNotContainsString('SQL', $mapped->getMessage());
    }

    public function testARawDeadlockOfAJsonRouteIsA503Body(): void
    {
        $event = $this->event($this->request('nowo_uptime_push', PushController::class), new DeadlockException($this->createStub(DriverException::class), null));

        (new StorageFailureSubscriber())->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame('{"error":"unavailable"}', $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testATransientFailureWithoutAHintWaitsOneSecondOnTheApi(): void
    {
        // A renamed tenant-path route of an API controller is still the bundle's.
        $event = $this->event($this->request('host_status', 'Nowo\UptimeMonitorBundle\Controller\Api\StatusApiController::status'), new TransientStorageFailure());

        (new StorageFailureSubscriber())->onKernelException($event);

        self::assertSame('1', $event->getResponse()?->headers->get('Retry-After'));
    }

    public function testAUniqueConflictIsA409(): void
    {
        $conflict = new UniqueConstraintViolationException($this->createStub(DriverException::class), null);
        $page     = $this->event($this->request('nowo_uptime_tenant_new', null), $conflict);
        $json     = $this->event($this->request('nowo_uptime_tenant_new', null, 'application/json'), $conflict);

        (new StorageFailureSubscriber())->onKernelException($page);
        (new StorageFailureSubscriber())->onKernelException($json);

        self::assertInstanceOf(ConflictHttpException::class, $page->getThrowable());
        self::assertSame($conflict, $page->getThrowable()->getPrevious());
        self::assertSame(409, $json->getResponse()?->getStatusCode());
        self::assertSame('{"error":"conflict"}', $json->getResponse()->getContent());
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
