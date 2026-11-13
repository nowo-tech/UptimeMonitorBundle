<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Doctrine;

use ArrayObject;
use Nowo\UptimeMonitorBundle\Doctrine\UnitOfWorkBoundaries;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use WeakMap;

use function array_pop;

/**
 * Dispatches real boundary events through a real dispatcher holding {@see UnitOfWorkBoundaries} and the subscriber
 * under test (1.28.0 unit tests, copied from PageBuilderKitBundle 1.15.1).
 */
final readonly class BoundaryEvents
{
    public EventDispatcher $dispatcher;

    /** @var WeakMap<Command, ArrayInput> one input per command run, as Symfony passes the same input to its events */
    private WeakMap $inputs;

    /** @var ArrayObject<int, Request> main requests started and not finished yet, innermost last (Symfony passes the same Request to every event) */
    private ArrayObject $open;

    /** @var ArrayObject<string, Request> the last main request started (for its `kernel.terminate`) */
    private ArrayObject $last;

    public function __construct(
        public UnitOfWorkBoundaries $boundaries,
        EventSubscriberInterface ...$subscribers,
    ) {
        $this->dispatcher = new EventDispatcher();
        $this->inputs     = new WeakMap();
        /** @var ArrayObject<int, Request> $open */
        $open = new ArrayObject();
        /** @var ArrayObject<string, Request> $last */
        $last       = new ArrayObject();
        $this->open = $open;
        $this->last = $last;
        $this->dispatcher->addSubscriber($this->boundaries);
        foreach ($subscribers as $subscriber) {
            $this->dispatcher->addSubscriber($subscriber);
        }
    }

    /**
     * Starts a run of $command; $input distinguishes a command that runs itself in-process (default: one per command).
     */
    public function command(Command $command, ?ArrayInput $input = null): void
    {
        $this->dispatcher->dispatch(new ConsoleCommandEvent($command, $input ?? $this->input($command), new NullOutput()), UnitOfWorkBoundaries::CONSOLE_COMMAND);
    }

    public function error(?Command $command, ?ArrayInput $input = null): void
    {
        $input ??= $command instanceof Command ? $this->input($command) : new ArrayInput([]);
        $this->dispatcher->dispatch(new ConsoleErrorEvent($input, new NullOutput(), new RuntimeException('failed'), $command), UnitOfWorkBoundaries::CONSOLE_ERROR);
    }

    public function terminate(Command $command, ?ArrayInput $input = null): void
    {
        $this->dispatcher->dispatch(new ConsoleTerminateEvent($command, $input ?? $this->input($command), new NullOutput(), 0), UnitOfWorkBoundaries::CONSOLE_TERMINATE);
    }

    private function input(Command $command): ArrayInput
    {
        return $this->inputs[$command] ??= new ArrayInput([]);
    }

    public function request(int $type = HttpKernelInterface::MAIN_REQUEST): Request
    {
        $request = new Request();
        if ($type === HttpKernelInterface::MAIN_REQUEST) {
            $this->open->append($request);
            $this->last['request'] = $request;
        }

        $this->dispatcher->dispatch(new RequestEvent(new StubHttpKernel(), $request, $type), KernelEvents::REQUEST);

        return $request;
    }

    /**
     * Finishes $request, by default the innermost main request still open (a new one when none is open).
     */
    public function finishRequest(int $type = HttpKernelInterface::MAIN_REQUEST, ?Request $request = null): void
    {
        if (!$request instanceof Request && $type === HttpKernelInterface::MAIN_REQUEST) {
            $open    = $this->open->getArrayCopy();
            $request = array_pop($open);
            $this->open->exchangeArray($open);
        }

        $this->dispatcher->dispatch(new FinishRequestEvent(new StubHttpKernel(), $request ?? new Request(), $type), KernelEvents::FINISH_REQUEST);
    }

    public function exception(int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $this->dispatcher->dispatch(new ExceptionEvent(new StubHttpKernel(), new Request(), $type, new RuntimeException('failed')), KernelEvents::EXCEPTION);
    }

    /**
     * `kernel.terminate` of $request, by default the last main request started.
     */
    public function kernelTerminate(?Request $request = null): void
    {
        $this->dispatcher->dispatch(new TerminateEvent(new StubHttpKernel(), $request ?? $this->last['request'] ?? new Request(), new Response()), KernelEvents::TERMINATE);
    }

    /**
     * A full main request: request, finish, terminate.
     */
    public function mainRequest(): void
    {
        $this->request();
        $this->finishRequest();
        $this->kernelTerminate();
    }

    public function message(string $eventName): void
    {
        $this->dispatcher->dispatch(new stdClass(), $eventName);
    }
}
