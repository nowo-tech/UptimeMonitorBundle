<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\EventSubscriber;

use Nowo\UptimeMonitorBundle\Doctrine\UnitOfWorkBoundaries;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\EventSubscriber\WorkerBoundaryResetListener;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\UptimeMonitorBundle\Tests\Unit\Doctrine\BoundaryEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

use function dirname;

#[CoversClass(WorkerBoundaryResetListener::class)]
final class WorkerBoundaryResetListenerTest extends TestCase
{
    public function testTheMainRequestAndEveryOtherBoundaryDropTheBundleManager(): void
    {
        $bundle   = $this->bundle();
        $listener = new WorkerBoundaryResetListener($bundle);
        $kernel   = $this->createStub(HttpKernelInterface::class);

        $bundle->live();
        $listener->onKernelRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::SUB_REQUEST));
        self::assertTrue($bundle->isInitialized(), 'a sub-request shares the main request\'s unit of work');

        $listener->onKernelRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
        self::assertFalse($bundle->isInitialized());

        $bundle->live();
        $listener->onBoundary();
        self::assertFalse($bundle->isInitialized(), 'onBoundary');

        $bundle->live();
        $listener->onKernelTerminate(new TerminateEvent($kernel, Request::create('/'), new Response()));
        self::assertFalse($bundle->isInitialized(), 'onKernelTerminate');

        $bundle->live();
        $listener->onKernelTerminate();
        self::assertFalse($bundle->isInitialized(), 'onKernelTerminate without its event (1.28.x callers)');

        $bundle->live();
        (new WorkerBoundaryResetListener($bundle, new UnitOfWorkBoundaries()))->onKernelTerminate();
        self::assertFalse($bundle->isInitialized(), 'without its event, judged on the current state');

        $bundle->live();
        $listener->onConsoleBoundary(new stdClass());
        self::assertFalse($bundle->isInitialized(), 'onConsoleBoundary (1.28.x callers)');

        $bundle->live();
        $listener->onUnitBoundary(new stdClass());
        self::assertFalse($bundle->isInitialized(), 'without a tracker every console boundary drops (1.27 behaviour)');
    }

    public function testAnUnusedBundleManagerIsNeverBuilt(): void
    {
        (new WorkerBoundaryResetListener())->onBoundary();

        $this->addToAssertionCount(1);
    }

    public function testEveryBoundaryEventHasAListenerMethod(): void
    {
        foreach ([...WorkerBoundaryResetListener::START_EVENTS, ...WorkerBoundaryResetListener::END_EVENTS] as $event) {
            self::assertArrayHasKey($event, WorkerBoundaryResetListener::METHODS);
        }
    }

    /**
     * 1.28.0: a unit run in-process inside another one never drops the outer unit's manager.
     */
    public function testNestedUnitsKeepTheOuterUnitsManager(): void
    {
        $bundle = $this->bundle();
        $events = $this->events($bundle);
        $outer  = new Command('app:outer');
        $nested = new Command('app:nested');

        $events->command($outer);
        $bundle->live();
        $events->command($nested);
        $events->mainRequest();
        $events->terminate($nested);
        self::assertTrue($bundle->isInitialized(), 'nested command and in-process request keep the outer command\'s manager');
        $events->terminate($outer);
        self::assertFalse($bundle->isInitialized(), 'the outermost command drops it');

        $events->message(UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_RECEIVED);
        $bundle->live();
        $events->command($nested);
        $events->terminate($nested);
        $events->mainRequest();
        self::assertTrue($bundle->isInitialized(), 'a command or a request run by a handler keeps the message\'s manager');
        $events->message(UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_HANDLED);
        self::assertFalse($bundle->isInitialized());

        $bundle->live();
        $events->request(HttpKernelInterface::MAIN_REQUEST);
        self::assertFalse($bundle->isInitialized(), 'a top-level main request drops it');
        $bundle->live();
        $events->command($nested);
        $events->terminate($nested);
        self::assertTrue($bundle->isInitialized(), 'a command run by a controller keeps the request\'s manager');
        $events->finishRequest();
        $events->kernelTerminate();
        self::assertFalse($bundle->isInitialized(), 'the top-level kernel.terminate drops it');
    }

    /**
     * Audit NM / NL2: a command whose error listener throws (no terminate) does not leave the process "inside a
     * command": the next main request drops the manager again.
     */
    public function testAFailedCommandWithoutTerminateHealsAtTheNextRequest(): void
    {
        $bundle  = $this->bundle();
        $events  = $this->events($bundle);
        $command = new Command('app:fail');

        $events->command($command);
        $events->error($command);
        $bundle->live();
        $events->request();
        self::assertFalse($bundle->isInitialized());
        $events->finishRequest();
        $events->kernelTerminate();
    }

    private function bundle(): UptimeEntityManager
    {
        return new UptimeEntityManager(new SqliteManagerRegistry(['default' => ['Nowo\\UptimeMonitorBundle\\Entity' => dirname(__DIR__, 3) . '/src/Entity']]));
    }

    /**
     * A real dispatcher wired like the bundle extension wires the listener.
     */
    private function events(UptimeEntityManager $bundle): BoundaryEvents
    {
        $boundaries = new UnitOfWorkBoundaries();
        $events     = new BoundaryEvents($boundaries);
        $listener   = new WorkerBoundaryResetListener($bundle, $boundaries);
        $events->dispatcher->addListener(KernelEvents::REQUEST, $listener->onKernelRequest(...), WorkerBoundaryResetListener::START_PRIORITY);
        foreach (WorkerBoundaryResetListener::START_EVENTS as $event) {
            $events->dispatcher->addListener($event, [$listener, WorkerBoundaryResetListener::METHODS[$event]], WorkerBoundaryResetListener::START_PRIORITY);
        }
        foreach (WorkerBoundaryResetListener::END_EVENTS as $event) {
            $events->dispatcher->addListener($event, [$listener, WorkerBoundaryResetListener::METHODS[$event]], WorkerBoundaryResetListener::END_PRIORITY);
        }

        return $events;
    }
}
