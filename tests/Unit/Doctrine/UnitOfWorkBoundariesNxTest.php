<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Doctrine;

use Closure;
use Nowo\UptimeMonitorBundle\Doctrine\UnitOfWorkBoundaries;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\EventSubscriber\WorkerBoundaryResetListener;
use Nowo\UptimeMonitorBundle\Tests\Integration\Worker\CallbackCommand;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageSkipEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

use function dirname;
use function gc_collect_cycles;

/**
 * Audit NX CR1–CR3 (tracker follow-up): per-worker message state and a stack of main requests, through a real
 * Messenger {@see Worker}, a real {@see HttpKernel} (`handle()` + `terminate()`, no services resetter) and a real
 * console {@see Application} run in-process, with {@see WorkerBoundaryResetListener} wired as the extension wires it.
 * "Kept" = the private manager the unit built is still there (no boundary dropped it).
 */
#[CoversClass(UnitOfWorkBoundaries::class)]
#[CoversClass(WorkerBoundaryResetListener::class)]
final class UnitOfWorkBoundariesNxTest extends TestCase
{
    private EventDispatcher $dispatcher;

    private UnitOfWorkBoundaries $boundaries;

    private HttpKernel $kernel;

    private UptimeEntityManager $bundle;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
        $requestStack     = new RequestStack();
        $this->boundaries = new UnitOfWorkBoundaries($requestStack);
        $this->bundle     = new UptimeEntityManager(new SqliteManagerRegistry(['default' => ['Nowo\\UptimeMonitorBundle\\Entity' => dirname(__DIR__, 3) . '/src/Entity']]));
        $listener         = new WorkerBoundaryResetListener($this->bundle, $this->boundaries);
        $this->dispatcher->addSubscriber($this->boundaries);
        $this->dispatcher->addListener(KernelEvents::REQUEST, $listener->onKernelRequest(...), WorkerBoundaryResetListener::START_PRIORITY);
        foreach (WorkerBoundaryResetListener::START_EVENTS as $event) {
            $this->dispatcher->addListener($event, [$listener, WorkerBoundaryResetListener::METHODS[$event]], WorkerBoundaryResetListener::START_PRIORITY);
        }
        foreach (WorkerBoundaryResetListener::END_EVENTS as $event) {
            $this->dispatcher->addListener($event, [$listener, WorkerBoundaryResetListener::METHODS[$event]], WorkerBoundaryResetListener::END_PRIORITY);
        }
        $this->kernel = new HttpKernel($this->dispatcher, new ControllerResolver(), $requestStack, new ArgumentResolver());
    }

    /**
     * CR1: a worker run in-process inside a message handler, then a main request in the same handler: neither drops
     * the outer message's manager; the outer message ends when handled.
     */
    public function testAWorkerInsideAHandlerNeverEndsTheOuterMessage(): void
    {
        $kept = null;
        $this->worker(1, function () use (&$kept): void {
            $this->bundle->live();
            $this->worker(1, static function (): void {
            });
            self::assertTrue($this->boundaries->isMessageInFlight(), 'the inner worker stopped, the outer message runs');
            $this->request(static function (): void {
            }, false);
            $kept = $this->bundle->isInitialized();
        });

        self::assertTrue($kept);
        self::assertFalse($this->bundle->isInitialized(), 'the outer message drops it when handled');
        self::assertFalse($this->boundaries->isMessageInFlight());
        $this->assertRequestDrops();
    }

    /**
     * CR2: a main request handled in-process inside another main request does not drop the outer one's manager.
     */
    public function testANestedMainRequestNeverDropsTheOuterRequestManager(): void
    {
        $kept = null;
        $this->request(function () use (&$kept): void {
            $this->bundle->live();
            $this->request(static function (): void {
            }, false);
            self::assertSame(1, $this->boundaries->requestsInFlight(), 'the nested request popped itself only');
            $kept = $this->bundle->isInitialized();
        });

        self::assertTrue($kept);
        self::assertFalse($this->bundle->isInitialized(), 'the outer terminate drops it');
        self::assertSame(0, $this->boundaries->requestsInFlight());
    }

    /**
     * CR3: a listener skips a message with `shouldHandle(false)`; the message ends anyway (CR3b: without any worker).
     */
    public function testASkippedMessageEndsWithoutAHandledEvent(): void
    {
        $inFlight = [];
        $running  = function () use (&$inFlight): void {
            $inFlight[] = $this->boundaries->isMessageInFlight();
        };
        $this->dispatcher->addListener(WorkerRunningEvent::class, $running);
        $received = 0;
        $handled  = 0;
        // The in-memory transport delivers the skipped (never acknowledged) message again.
        $this->worker(1, static function () use (&$handled): void {
            ++$handled;
        }, static function (WorkerMessageReceivedEvent $event) use (&$received): void {
            if (++$received === 1) {
                $event->shouldHandle(false);
            }
        });
        $this->dispatcher->removeListener(WorkerRunningEvent::class, $running);

        self::assertSame(1, $handled);
        self::assertSame([false, false, false], $inFlight);
        $this->assertRequestDrops();

        $event = new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'memory');
        $event->shouldHandle(false);
        $this->dispatcher->dispatch($event);
        self::assertFalse($this->boundaries->isMessageInFlight());
        $this->assertRequestDrops();
    }

    public function testANestedCommandNeverDropsTheOuterCommandManager(): void
    {
        $application = $this->application();
        $kept        = null;
        $application->addCommand(new CallbackCommand('app:inner', static fn (): int => 0));
        $application->addCommand(new CallbackCommand('app:outer', function () use ($application, &$kept): int {
            $this->bundle->live();
            $application->run(new ArrayInput(['command' => 'app:inner']), new NullOutput());
            $kept = $this->bundle->isInitialized();

            return 0;
        }));

        self::assertSame(0, $application->run(new ArrayInput(['command' => 'app:outer']), new NullOutput()));
        self::assertTrue($kept);
        self::assertFalse($this->bundle->isInitialized(), 'the outer command drops it at its end');
        $this->assertRequestDrops();
    }

    public function testASynchronousMessageInsideARequestBelongsToTheRequest(): void
    {
        $handled = 0;
        $bus     = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([stdClass::class => [static function () use (&$handled): void {
            ++$handled;
        }]]))]);
        $kept = null;
        $this->request(function () use ($bus, &$kept): void {
            $this->bundle->live();
            $bus->dispatch(new stdClass());
            $kept = $this->bundle->isInitialized();
        });

        self::assertSame(1, $handled);
        self::assertTrue($kept);
        self::assertFalse($this->bundle->isInitialized());
    }

    public function testAWorkerInsideACommandDropsAtEveryMessage(): void
    {
        $application = $this->application();
        $kept        = [];
        $application->addCommand(new CallbackCommand('app:consume', function () use (&$kept): int {
            $this->worker(2, function () use (&$kept): void {
                $kept[] = $this->bundle->isInitialized();
                $this->bundle->live();
            });

            return 0;
        }));

        self::assertSame(0, $application->run(new ArrayInput(['command' => 'app:consume']), new NullOutput()));
        self::assertSame([false, false], $kept, 'every message starts with no manager');
        self::assertFalse($this->bundle->isInitialized());
        self::assertFalse($this->boundaries->isMessageInFlight());
        $this->assertRequestDrops();
    }

    /**
     * Two workers one after the other; the first one's stop never came (a WorkerRunningEvent listener threw).
     */
    public function testTwoSequentialWorkersKeepTheirOwnState(): void
    {
        $this->worker(1, function (): void {
            $this->bundle->live();
        });
        self::assertFalse($this->bundle->isInitialized());

        $throw = static function (): never {
            throw new RuntimeException('listener failed');
        };
        $this->dispatcher->addListener(WorkerRunningEvent::class, $throw);
        try {
            $this->worker(1, static function (): void {
            });
            self::fail('the running listener throws');
        } catch (RuntimeException) {
        } finally {
            $this->dispatcher->removeListener(WorkerRunningEvent::class, $throw);
        }

        self::assertFalse($this->boundaries->isMessageInFlight());
        $this->bundle->live();
        $this->worker(1, function (): void {
            self::assertFalse($this->bundle->isInitialized(), 'the second worker\'s message is the outermost unit');
            self::assertTrue($this->boundaries->isMessageInFlight());
            $this->bundle->live();
        });
        self::assertFalse($this->bundle->isInitialized());
        $this->assertRequestDrops();
    }

    public function testUnbalancedEventsNeverEndAnotherUnit(): void
    {
        $this->worker(1, function (): void {
            $stranger = new Worker([], $this->createStub(MessageBusInterface::class));
            $this->dispatcher->dispatch(new WorkerRunningEvent($stranger, false));
            $this->dispatcher->dispatch(new WorkerStoppedEvent($stranger));
            $this->dispatcher->dispatch(new stdClass(), UnitOfWorkBoundaries::MESSENGER_WORKER_STOPPED);
            self::assertTrue($this->boundaries->isMessageInFlight(), 'another worker\'s events do not end this message');
        });
        self::assertFalse($this->boundaries->isMessageInFlight());

        $this->request(function (): void {
            $this->dispatcher->dispatch(new RequestEvent($this->kernel, Request::create('/lost'), HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
            self::assertSame(2, $this->boundaries->requestsInFlight());
        }, false);
        self::assertSame(0, $this->boundaries->requestsInFlight(), 'the outer finish pops the lost nested request');
        $this->assertRequestDrops();
    }

    public function testAnExceptionMidUnitNeverLeavesAMessageInFlight(): void
    {
        $this->worker(1, function (): never {
            $this->bundle->live();

            throw new RuntimeException('handler failed');
        });
        self::assertFalse($this->bundle->isInitialized(), 'the failed message drops it');
        self::assertFalse($this->boundaries->isMessageInFlight());

        $throw = static function (): never {
            throw new RuntimeException('received listener failed');
        };
        $application = $this->application();
        $application->addCommand(new CallbackCommand('app:consume', function () use ($throw): int {
            $this->worker(1, static function (): void {
            }, $throw);

            return 0;
        }));
        self::assertNotSame(0, $application->run(new ArrayInput(['command' => 'app:consume']), new NullOutput()));
        self::assertFalse($this->boundaries->isMessageInFlight(), 'the command frame restored the state of its start');
        $this->assertRequestDrops();

        try {
            $this->worker(1, static function (): void {
            }, $throw);
            self::fail('the received listener throws');
        } catch (RuntimeException) {
        }
        self::assertFalse($this->boundaries->isMessageInFlight(), 'the aborted run is gone with its worker (audit OB-M1)');
        $this->assertRequestDrops();

        // A top-level request whose kernel.exception listener throws (no finish_request, no terminate).
        $this->dispatcher->addListener(KernelEvents::EXCEPTION, $throw);
        try {
            $this->request(static function (): never {
                throw new RuntimeException('controller failed');
            }, true, true);
            self::fail('the kernel.exception listener throws');
        } catch (RuntimeException) {
        } finally {
            $this->dispatcher->removeListener(KernelEvents::EXCEPTION, $throw);
        }
        self::assertSame(1, $this->boundaries->requestsInFlight());
        $this->assertRequestDrops();
        self::assertSame(0, $this->boundaries->requestsInFlight());
    }

    /**
     * Audit OB-M1 (S8): a long-running command runs workers in a loop and catches their exceptions; the first run aborts
     * in a throwing received listener. Every later message still starts and ends with no manager.
     */
    public function testAnAbortedWorkerInsideALongCommandNeverStopsTheNextMessagesFromDropping(): void
    {
        $application = $this->application();
        $kept        = [];
        $application->addCommand(new CallbackCommand('app:loop', function () use (&$kept): int {
            $throw = static function (): never {
                throw new RuntimeException('received listener failed');
            };
            $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, $throw);
            try {
                $this->worker(1, static function (): void {
                });
            } catch (RuntimeException) {
            } finally {
                $this->dispatcher->removeListener(WorkerMessageReceivedEvent::class, $throw);
            }

            for ($i = 0; $i < 3; ++$i) {
                $this->worker(2, function () use (&$kept): void {
                    $kept[] = $this->bundle->isInitialized();
                    $this->bundle->live();
                });
            }

            return 0;
        }));

        self::assertSame(0, $application->run(new ArrayInput(['command' => 'app:loop']), new NullOutput()));
        self::assertSame([false, false, false, false, false, false], $kept, 'every message starts with no manager');
        $this->assertRequestDrops();
    }

    /**
     * Audit OB-L1 (S13): 500 runs aborted by a throwing handled listener inside one command leave a bounded stack.
     */
    public function testAbortedRunsNeverGrowTheWorkerStack(): void
    {
        $application = $this->application();
        $entries     = null;
        $application->addCommand(new CallbackCommand('app:loop', function () use (&$entries): int {
            $throw = static function (): never {
                throw new RuntimeException('handled listener failed');
            };
            $this->dispatcher->addListener(WorkerMessageHandledEvent::class, $throw);
            for ($i = 0; $i < 500; ++$i) {
                try {
                    $this->worker(1, static function (): void {
                    });
                } catch (RuntimeException) {
                }
            }
            $this->dispatcher->removeListener(WorkerMessageHandledEvent::class, $throw);
            gc_collect_cycles();
            $entries = $this->boundaries->workerEntries();

            return 0;
        }));

        self::assertSame(0, $application->run(new ArrayInput(['command' => 'app:loop']), new NullOutput()));
        self::assertSame(0, $entries, 'no aborted run is kept');
    }

    /**
     * Audit OB-L2 (S9): a received listener of an inner worker dispatches `WorkerMessageSkipEvent` and skips the
     * message: only the inner message ends, the outer one keeps its manager.
     */
    public function testASkipEventFromAReceivedListenerNeverEndsTheOuterMessage(): void
    {
        $kept = null;
        $this->worker(1, function () use (&$kept): void {
            $this->bundle->live();
            $skip = function (WorkerMessageReceivedEvent $event): void {
                $this->dispatcher->dispatch(new WorkerMessageSkipEvent($event->getEnvelope(), $event->getReceiverName()));
                $event->shouldHandle(false);
            };
            $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, $skip);
            $this->worker(1, static function (): void {
            }, null, true);
            $this->dispatcher->removeListener(WorkerMessageReceivedEvent::class, $skip);
            self::assertTrue($this->boundaries->isMessageInFlight());
            $this->request(static function (): void {
            });
            $kept = $this->bundle->isInitialized();
        });

        self::assertTrue($kept);
        $this->assertRequestDrops();
    }

    /**
     * Audit OB-L3 (S10): the in-process `kernel.terminate` of a nested main request keeps the outer request's manager.
     */
    public function testTheTerminateOfANestedMainRequestKeepsTheOuterRequest(): void
    {
        $kept = null;
        $this->request(function () use (&$kept): void {
            $this->bundle->live();
            $this->request(static function (): void {
            });
            $kept = $this->bundle->isInitialized();
        });

        self::assertTrue($kept);
        self::assertFalse($this->bundle->isInitialized());
        $this->assertRequestDrops();
    }

    /**
     * The next top-level request drops the manager at its start (and again at its terminate).
     */
    private function assertRequestDrops(): void
    {
        $this->bundle->live();
        $atStart = null;
        $this->request(function () use (&$atStart): void {
            $atStart = $this->bundle->isInitialized();
            $this->bundle->live();
        });
        self::assertFalse($atStart, 'a top-level request drops the manager at its start');
        self::assertFalse($this->bundle->isInitialized(), 'and at its terminate');
    }

    private function request(Closure $body, bool $terminate = true, bool $catch = false): void
    {
        $request = Request::create('/');
        $request->attributes->set('_controller', static function () use ($body): Response {
            $body();

            return new Response('ok');
        });
        $response = $this->kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, $catch);
        if ($terminate) {
            $this->kernel->terminate($request, $response);
        }
    }

    private function worker(int $count, Closure $handler, ?Closure $onReceived = null, bool $stopAfterFirst = false): void
    {
        $transport = new InMemoryTransport();
        for ($i = 0; $i < $count; ++$i) {
            $transport->send(new Envelope(new stdClass()));
        }

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use ($handler): Envelope {
            $handler();

            return $message instanceof Envelope ? $message : new Envelope($message);
        });
        $worker = new Worker(['memory' => $transport], $bus, $this->dispatcher);
        $stop   = static function (WorkerRunningEvent $event) use ($worker, $stopAfterFirst): void {
            if ($event->getWorker() === $worker && ($stopAfterFirst || $event->isWorkerIdle())) {
                $worker->stop();
            }
        };
        $this->dispatcher->addListener(WorkerRunningEvent::class, $stop, -1);
        if ($onReceived instanceof Closure) {
            $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, $onReceived);
        }

        try {
            $worker->run(['sleep' => 0]);
        } finally {
            $this->dispatcher->removeListener(WorkerRunningEvent::class, $stop);
            if ($onReceived instanceof Closure) {
                $this->dispatcher->removeListener(WorkerMessageReceivedEvent::class, $onReceived);
            }
        }
    }

    private function application(): Application
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);
        $application->setDispatcher($this->dispatcher);

        return $application;
    }
}
