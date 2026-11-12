<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Doctrine;

use Nowo\UptimeMonitorBundle\EventSubscriber\WorkerBoundaryResetListener;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageSkipEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use WeakMap;
use WeakReference;

use function array_key_last;
use function array_pop;
use function array_splice;
use function count;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Tracks which unit of work is running in this process, so the bundle acts only at the OUTERMOST boundary of a
 * request, a Messenger message or a console command (1.28.0, REQ-RUNTIME-002 rule 4). Used by
 * {@see WorkerBoundaryResetListener}, which drops the bundle's private
 * manager only at those boundaries: a unit run in-process inside another one (a command started by a command, a
 * message handler or a controller; a request handled by a command or a handler) no longer drops the manager of the
 * unit that runs it. Copy of the audited PageBuilderKitBundle 1.15.1 tracker (audits NM, NL2-v, NR-b; NX CR1–CR3).
 *
 * The state changes at priority `PHP_INT_MAX`, before any other listener can throw:
 * - console: a stack of frames, one per running command, keyed by the Command AND its input (a command that runs
 *   itself in-process gets two frames). A frame remembers whether its command started as the outermost unit (no
 *   other frame, no message, no main request) and saves the worker / request stacks of the moment it was pushed;
 *   removing the frame restores them, so a message or request whose closing event never came (a throwing
 *   `WorkerMessageReceivedEvent` or `kernel.exception` listener inside the command) does not outlive the command;
 * - `console.terminate` removes ITS frame (and the frames above it, nested commands that never terminated); a
 *   terminate without a matching frame never removes another command's frame;
 * - `console.error` marks the frame as failing until its own terminate. When an error listener throws, Symfony
 *   never dispatches that terminate: the marked frame (and those above it) is dropped at the next request, message,
 *   command or worker event (self-healing, so the process never stays "inside a command" for good);
 * - main requests: a STACK of the main `Request` objects in flight (audit NX CR2). A main request is the outermost
 *   unit only when no other main request is in flight, so a main request handled in-process inside another one never
 *   clears the outer one. `kernel.finish_request` / `kernel.terminate` pop their request and the requests above it
 *   (nested requests whose end never came); an unknown request pops nothing. A main request that is the HTTP
 *   kernel's own main request (its `RequestStack`, popped in a `finally`) drops the requests left on the stack by a
 *   request whose end never came (a throwing `kernel.exception` listener). `kernel.terminate` is the outermost unit
 *   only when nothing runs any more (the terminate of a nested main request keeps the outer one, audit OB-L3);
 * - Messenger: a STACK of the running workers (`WorkerStartedEvent` → `WorkerStoppedEvent`), each with its message in
 *   flight (audit NX CR1). A worker run in-process inside a message handler gets its own entry, so its events never
 *   end the outer message. A message ends at handled / failed / `WorkerMessageSkipEvent` (dispatched only by
 *   `messenger:failed:retry`), at its worker's next `WorkerRunningEvent` (also dispatched after a message a listener
 *   skipped with `shouldHandle(false)`, audit NX CR3) or its worker's stop; a received event whose final
 *   `shouldHandle()` is false (read at `PHP_INT_MIN`) ends it too. A message received outside any worker the tracker
 *   saw start gets an entry without a worker. A run that aborted (an exception thrown out of `Worker::run()`: no
 *   handled, running or stopped event) is dropped as soon as its worker or its in-flight received event is gone
 *   (weak references, audit OB-M1 / OB-L1).
 *
 * Rule for every handler: failed frames are dropped FIRST (which restores the state saved with them), THEN the
 * handler applies its own change, so a restored state never overrides the event being handled.
 *
 * The verdict for each boundary event ("was this the outermost unit?") is kept per event object (`WeakMap`), so the
 * late listeners that act on it read the value computed when the event started, whatever ran in between.
 *
 * Process-lifetime state, deliberately NOT reset by `kernel.reset`: it describes the units still running.
 *
 * @internal
 */
final class UnitOfWorkBoundaries implements EventSubscriberInterface
{
    /** Messenger event names (class names), subscribed without requiring symfony/messenger. */
    public const MESSENGER_WORKER_STARTED          = WorkerStartedEvent::class;
    public const MESSENGER_WORKER_MESSAGE_RECEIVED = WorkerMessageReceivedEvent::class;
    public const MESSENGER_WORKER_MESSAGE_HANDLED  = WorkerMessageHandledEvent::class;
    public const MESSENGER_WORKER_MESSAGE_FAILED   = WorkerMessageFailedEvent::class;
    public const MESSENGER_WORKER_MESSAGE_SKIPPED  = WorkerMessageSkipEvent::class;
    public const MESSENGER_WORKER_RUNNING          = WorkerRunningEvent::class;
    public const MESSENGER_WORKER_STOPPED          = WorkerStoppedEvent::class;

    /** Console event names (ConsoleEvents::COMMAND / ERROR / TERMINATE). */
    public const CONSOLE_COMMAND   = 'console.command';
    public const CONSOLE_ERROR     = 'console.error';
    public const CONSOLE_TERMINATE = 'console.terminate';

    /** @var list<array{command: object, input: object|null, outermost: bool, failing: bool, workers: list<array{worker: WeakReference<object>|null, message: bool, event: WeakReference<object>|null}>, requests: list<object>}> running commands, outermost first */
    private array $frames = [];

    /**
     * Running Messenger workers and their message in flight, outermost first. The worker and the received event are
     * weak references: `Worker::handleMessage()` keeps its received event alive until it returns, so a dead event with
     * the message still in flight (or a dead worker) means the run aborted (audit OB-M1).
     *
     * @var list<array{worker: WeakReference<object>|null, message: bool, event: WeakReference<object>|null}>
     */
    private array $workers = [];

    /** @var list<object> main requests in flight (main `kernel.request` → `kernel.finish_request`), outermost first */
    private array $requests = [];

    /** @var WeakMap<object, bool> outermost verdict per boundary event */
    private WeakMap $verdicts;

    /**
     * @param RequestStack|null $requestStack the HTTP kernel's request stack (read only), to tell a nested main request
     *                                        from one left on the stack by a request whose end never came
     */
    public function __construct(
        private readonly ?RequestStack $requestStack = null,
    ) {
        $this->verdicts = new WeakMap();
    }

    /**
     * @return array<string, array{0: string, 1: int}|list<array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                   => ['onRequest', PHP_INT_MAX],
            KernelEvents::FINISH_REQUEST            => ['onFinishRequest', PHP_INT_MAX],
            KernelEvents::TERMINATE                 => ['onKernelTerminate', PHP_INT_MAX],
            self::CONSOLE_COMMAND                   => ['onCommand', PHP_INT_MAX],
            self::CONSOLE_ERROR                     => ['onError', PHP_INT_MAX],
            self::CONSOLE_TERMINATE                 => ['onTerminate', PHP_INT_MAX],
            self::MESSENGER_WORKER_STARTED          => ['onWorkerStarted', PHP_INT_MAX],
            self::MESSENGER_WORKER_MESSAGE_RECEIVED => [['onMessageReceived', PHP_INT_MAX], ['onMessageReceivedDecided', PHP_INT_MIN]],
            self::MESSENGER_WORKER_MESSAGE_HANDLED  => ['onMessageFinished', PHP_INT_MAX],
            self::MESSENGER_WORKER_MESSAGE_FAILED   => ['onMessageFinished', PHP_INT_MAX],
            self::MESSENGER_WORKER_MESSAGE_SKIPPED  => ['onMessageFinished', PHP_INT_MAX],
            self::MESSENGER_WORKER_RUNNING          => ['onWorkerRunning', PHP_INT_MAX],
            self::MESSENGER_WORKER_STOPPED          => ['onWorkerStopped', PHP_INT_MAX],
        ];
    }

    /**
     * Main `kernel.request`: outermost only when nothing runs, not even another main request (audit NX CR2).
     */
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->dropStaleUnits();
        $request = $event->getRequest();
        if ($this->requests !== [] && $this->requestStack?->getMainRequest() === $request) {
            // The HTTP kernel runs no other request (it pops its own stack in a `finally`): the requests left here never
            // reached their finish_request / terminate (a throwing `kernel.exception` listener).
            // @igor-ignore - Stack of the main requests in flight (no request data kept), healed at a top-level start.
            $this->requests = [];
        }

        // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
        $this->verdicts[$event] = $this->isIdle();
        // @igor-ignore - Stack of the main requests in flight, popped at their own finish_request / terminate.
        $this->requests[] = $request;
    }

    /**
     * Main `kernel.finish_request`: pops the request and the nested requests above it.
     */
    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->dropStaleUnits();
            $this->endRequest($event->getRequest());
        }
    }

    /**
     * End of a request: pops it (and the requests above it whose end never came). The outermost unit only when nothing
     * runs any more, so the `kernel.terminate` of a main request handled in-process inside another one never clears the
     * outer request (audit OB-L3). Requests left below by a request whose end never came are dropped at the next main
     * request of the HTTP kernel ({@see onRequest()}).
     */
    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->dropStaleUnits();
        $this->endRequest($event->getRequest());
        // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
        $this->verdicts[$event] = $this->isIdle();
    }

    /**
     * `console.command`: pushes the command's frame and records whether it is the outermost unit.
     */
    public function onCommand(ConsoleCommandEvent $event): void
    {
        $this->dropStaleUnits();
        $outermost = $this->isIdle();
        // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
        $this->verdicts[$event] = $outermost;
        // @igor-ignore - Stack of the running commands (no request data), popped by their own terminate.
        $this->frames[] = [
            'command'   => $event->getCommand() ?? $event,
            'input'     => $event->getInput(),
            'outermost' => $outermost,
            'failing'   => false,
            'workers'   => $this->workers,
            'requests'  => $this->requests,
        ];
    }

    /**
     * A command failed (or was not found: no command, no frame). Its verdict is its frame's, and the frame stays
     * marked until its own terminate; without a frame it is the outermost unit only when nothing else runs.
     */
    public function onError(ConsoleErrorEvent $event): void
    {
        $index = $this->indexOf($event->getCommand(), $event->getInput());
        if ($index === null) {
            // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
            $this->verdicts[$event] = $this->isIdle();

            return;
        }

        // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
        $this->verdicts[$event] = $this->frames[$index]['outermost'];
        // @igor-ignore - Failure marker of a running command, removed with its frame.
        $this->frames[$index]['failing'] = true;
    }

    /**
     * `console.terminate`: removes the command's frame (and nested frames that never terminated).
     */
    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $index = $this->indexOf($event->getCommand(), $event->getInput());
        if ($index === null) {
            $this->dropStaleUnits();
            // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
            $this->verdicts[$event] = $this->isIdle();

            return;
        }

        // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
        $this->verdicts[$event] = $this->frames[$index]['outermost'];
        // Its own frame and the nested commands above it that never terminated.
        $this->removeFrames($index);
    }

    /**
     * A worker starts: its own entry, so a worker run inside a message handler never ends the outer message.
     */
    public function onWorkerStarted(object $event): void
    {
        $this->dropStaleUnits();
        if (!$event instanceof WorkerStartedEvent) {
            return;
        }

        $worker = $event->getWorker();
        $index  = $this->indexOfWorker($worker);
        if ($index !== null) {
            // A previous run of the same worker whose stop never came.
            // @igor-ignore - Stack of the running workers (no message data).
            array_splice($this->workers, $index);
        }

        // @igor-ignore - Stack of the running workers (no message data), popped by their own stop.
        $this->workers[] = ['worker' => WeakReference::create($worker), 'message' => false, 'event' => null];
    }

    /**
     * A message is received: outermost when no other message is in flight (audit NX CR1).
     */
    public function onMessageReceived(object $event): void
    {
        $this->dropStaleUnits();
        // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
        $this->verdicts[$event] = !$this->messageInFlight();
        $received               = $event instanceof WorkerMessageReceivedEvent ? WeakReference::create($event) : null;
        $top                    = array_key_last($this->workers);
        if ($top === null || $this->workers[$top]['message']) {
            // @igor-ignore - A message received outside any worker the tracker saw start (no message data).
            $this->workers[] = ['worker' => null, 'message' => true, 'event' => $received];

            return;
        }

        // @igor-ignore - Unit-of-work flag of the worker, set and unset at the Messenger boundaries.
        $this->workers[$top]['message'] = true;
        // @igor-ignore - Weak reference to the received event of the message in flight (liveness of the run).
        $this->workers[$top]['event'] = $received;
    }

    /**
     * End of the received event: a listener that called `shouldHandle(false)` skipped the message, which the Worker then
     * never reports as handled or failed (audit NX CR3). Reads the final decision; marks nothing. Only THIS event's
     * message ends, and only while it is still in flight: a listener that already ended it (a `WorkerMessageSkipEvent`
     * dispatched by `messenger:failed:retry`) never makes it end an outer message (audit OB-L2).
     */
    public function onMessageReceivedDecided(object $event): void
    {
        if (!$event instanceof WorkerMessageReceivedEvent || $event->shouldHandle()) {
            return;
        }

        $this->dropStaleUnits();
        for ($i = count($this->workers) - 1; $i >= 0; --$i) {
            if ($this->workers[$i]['message'] && $this->workers[$i]['event']?->get() === $event) {
                $this->endMessageAt($i);

                return;
            }
        }
    }

    /**
     * Handled, failed or skipped message: outermost when no other message is still in flight.
     */
    public function onMessageFinished(object $event): void
    {
        $this->dropStaleUnits();
        $this->endMessage();
        // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
        $this->verdicts[$event] = !$this->messageInFlight();
    }

    /**
     * Dispatched by the Worker after every message (handled, failed or skipped) and when idle: no message of that
     * worker is in flight, and the workers started above it are gone.
     */
    public function onWorkerRunning(object $event): void
    {
        $this->dropStaleUnits();
        $index = $this->indexOfWorker($event instanceof WorkerRunningEvent ? $event->getWorker() : null);
        if ($index === null) {
            $this->popWorkerlessMessages();

            return;
        }

        $this->endMessageAt($index);
    }

    /**
     * `WorkerStoppedEvent`: removes the worker's entry and the workers started above it.
     */
    public function onWorkerStopped(object $event): void
    {
        $this->dropStaleUnits();
        $index = $this->indexOfWorker($event instanceof WorkerStoppedEvent ? $event->getWorker() : null);
        if ($index === null) {
            $this->popWorkerlessMessages();

            return;
        }

        // Its own entry and the workers started above it that never stopped.
        // @igor-ignore - Stack of the running workers (no message data).
        array_splice($this->workers, $index);
    }

    /**
     * Whether the boundary event started or ended the outermost unit of work. An event this tracker did not see is
     * judged on the current state.
     */
    public function isOutermost(object $event): bool
    {
        return $this->verdicts[$event] ?? $this->isIdle();
    }

    /**
     * Neither a command nor a message runs (the current state; boundary listeners use {@see isOutermost()}).
     */
    public function isHttpOutermost(): bool
    {
        $this->dropAbortedRuns();

        return $this->frames === [] && !$this->messageInFlight();
    }

    /**
     * Number of Messenger workers (and messages received outside a known worker) the tracker holds.
     */
    public function workerEntries(): int
    {
        $this->dropAbortedRuns();

        return count($this->workers);
    }

    /**
     * Number of console commands running (tests and diagnostics).
     */
    public function runningCommands(): int
    {
        return count($this->frames);
    }

    /**
     * Number of main requests in flight (tests and diagnostics).
     */
    public function requestsInFlight(): int
    {
        return count($this->requests);
    }

    /**
     * Whether a Messenger message is in flight in any worker (tests and diagnostics).
     */
    public function isMessageInFlight(): bool
    {
        $this->dropAbortedRuns();

        return $this->messageInFlight();
    }

    private function isIdle(): bool
    {
        return $this->frames === [] && !$this->messageInFlight() && $this->requests === [];
    }

    private function messageInFlight(): bool
    {
        foreach ($this->workers as $worker) {
            if ($worker['message']) {
                return true;
            }
        }

        return false;
    }

    private function indexOf(?object $command, object $input): ?int
    {
        if ($command === null) {
            return null;
        }

        for ($i = count($this->frames) - 1; $i >= 0; --$i) {
            if ($this->frames[$i]['command'] === $command && $this->frames[$i]['input'] === $input) {
                return $i;
            }
        }

        return null;
    }

    private function indexOfWorker(?object $worker): ?int
    {
        if ($worker === null) {
            return null;
        }

        for ($i = count($this->workers) - 1; $i >= 0; --$i) {
            if ($this->workers[$i]['worker']?->get() === $worker) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Pops $request and the requests above it (nested requests whose end never came); an unknown request pops nothing.
     */
    private function endRequest(object $request): void
    {
        for ($i = count($this->requests) - 1; $i >= 0; --$i) {
            if ($this->requests[$i] === $request) {
                // @igor-ignore - Stack of the main requests in flight (no request data kept).
                array_splice($this->requests, $i);

                return;
            }
        }
    }

    /**
     * Ends the innermost message in flight.
     */
    private function endMessage(): void
    {
        for ($i = count($this->workers) - 1; $i >= 0; --$i) {
            if ($this->workers[$i]['message']) {
                $this->endMessageAt($i);

                return;
            }
        }
    }

    /**
     * Ends the message of the entry at $index: the workers started above it (never stopped) are gone, an entry without a
     * worker is removed, a worker keeps running without a message.
     */
    private function endMessageAt(int $index): void
    {
        $worker = $this->workers[$index]['worker'];
        // @igor-ignore - Stack of the running workers (no message data).
        array_splice($this->workers, $index);
        if ($worker !== null) {
            // The worker keeps running, without a message.
            // @igor-ignore - Stack of the running workers (no message data).
            $this->workers[] = ['worker' => $worker, 'message' => false, 'event' => null];
        }
    }

    /**
     * Drops the runs that aborted (audit OB-M1): an exception thrown out of `Worker::run()` (a throwing received,
     * handled or running listener, a transport failure) leaves no handled, running or stopped event. Such a run is over
     * once its worker is gone or, with a message in flight, once the received event of that message is gone (the Worker
     * keeps it alive until `handleMessage()` returns). The entries above it ran inside it and are dropped with it, so the
     * stack never grows with aborted runs (audit OB-L1).
     */
    private function dropAbortedRuns(): void
    {
        foreach ($this->workers as $i => $entry) {
            if ($entry['worker'] === null) {
                continue;
            }

            if ($entry['worker']->get() === null || ($entry['message'] && $entry['event'] !== null && $entry['event']->get() === null)) {
                // @igor-ignore - Stack of the running workers (no message data).
                array_splice($this->workers, $i);

                return;
            }
        }
    }

    /**
     * Every handler first drops the failed command frames (restoring the state they saved), then the aborted worker
     * runs, and only then applies its own change.
     */
    private function dropStaleUnits(): void
    {
        $this->dropFailedFrames();
        $this->dropAbortedRuns();
    }

    /**
     * A running / stopped event of a worker the tracker never saw start: the messages received outside any known
     * worker are over; another worker's entry is never touched.
     */
    private function popWorkerlessMessages(): void
    {
        while ($this->workers !== [] && $this->workers[array_key_last($this->workers)]['worker'] === null) {
            // @igor-ignore - Stack of the running workers (no message data).
            array_pop($this->workers);
        }
    }

    /**
     * Removes the frame at $index and every frame above it, restoring the worker / request stacks saved when that
     * frame was pushed.
     */
    private function removeFrames(int $index): void
    {
        // @igor-ignore - Running workers restored with the command frame.
        $this->workers = $this->frames[$index]['workers'];
        // @igor-ignore - Main requests in flight, restored with the command frame.
        $this->requests = $this->frames[$index]['requests'];
        // @igor-ignore - Stack of the running commands (no request data).
        array_splice($this->frames, $index);
    }

    /**
     * Drops the commands whose error listeners threw (Symfony never dispatches their terminate), with the nested
     * frames above them.
     */
    private function dropFailedFrames(): void
    {
        foreach ($this->frames as $i => $frame) {
            if ($frame['failing']) {
                $this->removeFrames($i);

                return;
            }
        }
    }
}
