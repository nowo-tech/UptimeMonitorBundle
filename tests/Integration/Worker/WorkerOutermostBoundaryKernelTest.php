<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Worker;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Doctrine\UnitOfWorkBoundaries;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Tests\Integration\BothHosts\StandardHostTestKernel;
use RuntimeException;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function bin2hex;
use function gc_collect_cycles;
use function memory_get_usage;
use function random_bytes;
use function sprintf;
use function ucfirst;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * REQ-RUNTIME-002 (1.28.0): one kept-alive kernel (`http_kernel`, the services resetter never runs, as FrankenPHP
 * worker mode with `kernel.reset` disabled) drops the bundle's private manager only at the OUTERMOST unit-of-work
 * boundaries, and keeps doing so after listeners that throw at any boundary (audits NM, NL2-v, NR-b probes, adapted
 * from PageBuilderKitBundle 1.15.1).
 */
final class WorkerOutermostBoundaryKernelTest extends KernelTestCase
{
    /** Requests of the two-tenant worker loop. */
    private const ITERATIONS = 1000;

    /** Allowed memory growth after the warm-up. */
    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    private EntityManagerInterface $application;

    private UptimeEntityManager $bundle;

    private EventDispatcherInterface $dispatcher;

    /** @var array<string, int> monitor id per tenant slug */
    private array $monitors = [];

    protected static function getKernelClass(): string
    {
        return StandardHostTestKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $doctrine  = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $application = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $application);
        $this->application = $application;
        (new SchemaTool($application))->createSchema($application->getMetadataFactory()->getAllMetadata());
        $bundle = $container->get(UptimeEntityManager::class);
        self::assertInstanceOf(UptimeEntityManager::class, $bundle);
        $this->bundle = $bundle;
        $dispatcher   = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $this->dispatcher = $dispatcher;

        foreach (['alpha', 'beta'] as $slug) {
            $tenant  = new Tenant($slug, ucfirst($slug));
            $monitor = new Monitor($tenant, $slug . ' api', MonitorType::Https, 'https://' . $slug . '.test');
            $application->persist($tenant);
            $application->persist($monitor);
            $application->flush();
            $this->monitors[$slug] = (int) $monitor->getId();
        }
        $application->clear();
    }

    /**
     * Two tenants alternate on one kept-alive kernel: each request sees its own monitor with the name another process
     * saved just before, never the other tenant's monitor; memory stays flat.
     */
    public function testTwoTenantsAlternateOnAKeptAliveKernelWithFlatMemory(): void
    {
        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $slug  = $i % 2 === 0 ? 'alpha' : 'beta';
            $other = $slug === 'alpha' ? 'beta' : 'alpha';
            $name  = sprintf('%s monitor %d', $slug, $i);
            $this->application->getConnection()->executeStatement('UPDATE uptime_monitor SET name = ? WHERE id = ?', [$name, $this->monitors[$slug]]);

            [$status, $content] = $this->serve($this->path('nowo_uptime_monitor_show', ['tenantSlug' => $slug, 'id' => $this->monitors[$slug]]));
            self::assertSame(200, $status, $content);
            self::assertStringContainsString($name, $content, 'request ' . $i . ' read fresh rows of its tenant');
            self::assertStringNotContainsString($other . ' monitor', $content, 'request ' . $i . ' never shows the other tenant');
            self::assertFalse($this->bundle->isInitialized(), 'request ' . $i . ': kernel.terminate dropped the private manager');

            if ($i % 10 === 0) {
                [$status] = $this->serve($this->path('nowo_uptime_monitor_show', ['tenantSlug' => $slug, 'id' => $this->monitors[$other]]));
                self::assertSame(404, $status, 'request ' . $i . ': the other tenant\'s monitor is not found');
            }
            if ($i === 100) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(self::MEMORY_MARGIN, memory_get_usage() - $baseline, 'no per-request growth');
    }

    /**
     * A command run in-process by a command, a request handled in-process by a command and a command run by a message
     * handler never drop the manager of the unit that runs them (up to 1.27.0 they detached its entities).
     */
    public function testNestedUnitsKeepTheOuterUnitsEntitiesManaged(): void
    {
        $console = $this->console();
        $console->addCommand($this->command('app:nested', static fn (): int => 0));
        $kept = [];
        $console->addCommand($this->command('app:outer', function () use ($console, &$kept): int {
            $tenant = $this->loadTenant('alpha');
            $console->run($this->input('app:nested'), new NullOutput());
            $kept[] = $this->bundle->contains($tenant);
            $this->serve($this->path('nowo_uptime_tenant_index'));
            $kept[] = $this->bundle->contains($tenant);
            self::assertNotSame(0, $console->run($this->input('zzz:no-such-command'), new NullOutput()));
            $kept[] = $this->bundle->contains($tenant);

            return 0;
        }));

        self::assertSame(0, $console->run($this->input('app:outer'), new NullOutput()));
        self::assertSame([true, true, true], $kept, 'nested command, in-process request, unknown nested command');
        self::assertFalse($this->bundle->isInitialized(), 'the outermost command dropped it');

        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $tenant = $this->loadTenant('beta');
        self::assertSame(0, $console->run($this->input('app:nested'), new NullOutput()));
        $this->serve($this->path('nowo_uptime_tenant_index'));
        self::assertTrue($this->bundle->contains($tenant), 'a command or a request run by a handler keeps the message\'s manager');
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'));
        self::assertFalse($this->bundle->isInitialized());
    }

    /**
     * A throwing `console.terminate` listener and a throwing `console.error` listener (Symfony then never dispatches
     * the terminate) do not leave the process "inside a command".
     */
    public function testThrowingConsoleListenersNeverStopTheNextDrop(): void
    {
        $console = $this->console();
        $console->addCommand($this->command('app:ok', static fn (): int => 0));
        $console->addCommand($this->command('app:fail', static function (): never {
            throw new RuntimeException('command failed');
        }));

        foreach ([[ConsoleEvents::TERMINATE, 'app:ok', 0], [ConsoleEvents::ERROR, 'app:fail', 0], [ConsoleEvents::ERROR, 'app:fail', PHP_INT_MIN], [ConsoleEvents::TERMINATE, 'app:ok', PHP_INT_MAX]] as [$event, $command, $priority]) {
            $throw = $this->throwingListener();
            $this->dispatcher->addListener($event, $throw, $priority);
            self::assertNotSame(0, $console->run($this->input($command), new NullOutput()));
            $this->dispatcher->removeListener($event, $throw);

            $this->assertRequestDrops($command . ' with a throwing ' . $event . ' listener at ' . $priority);
            $this->assertNextCommandIsOutermost($command . ' with a throwing ' . $event . ' listener at ' . $priority);
        }
    }

    /**
     * A command that runs itself in-process, whose inner run's error listener throws, ends with its outer run; a
     * nested command whose error listener throws does not keep its outer command running.
     */
    public function testSelfRecursiveAndNestedFailingCommandsStillEnd(): void
    {
        $console = $this->console();
        $runs    = 0;
        $console->addCommand($this->command('app:recursive', function () use ($console, &$runs): int {
            if (++$runs === 1) {
                $throw = $this->throwingListener();
                $this->dispatcher->addListener(ConsoleEvents::ERROR, $throw);
                $console->run($this->input('app:recursive'), new NullOutput());
                $this->dispatcher->removeListener(ConsoleEvents::ERROR, $throw);

                return 0;
            }

            throw new RuntimeException('inner run failed');
        }));
        $console->addCommand($this->command('app:fail', static function (): never {
            throw new RuntimeException('command failed');
        }));
        $console->addCommand($this->command('app:outer', function () use ($console): int {
            $throw = $this->throwingListener();
            $this->dispatcher->addListener(ConsoleEvents::ERROR, $throw);
            $console->run($this->input('app:fail'), new NullOutput());
            $this->dispatcher->removeListener(ConsoleEvents::ERROR, $throw);

            return 0;
        }));

        foreach (['app:recursive', 'app:outer'] as $command) {
            self::assertSame(0, $console->run($this->input($command), new NullOutput()));
            self::assertFalse($this->bundle->isInitialized(), $command . ': the outermost terminate dropped the manager');
            $this->assertRequestDrops($command);
            $this->assertNextCommandIsOutermost($command);
        }
    }

    /**
     * A message or a request whose closing event never came inside an in-process command (a throwing
     * `WorkerMessageReceived` or `kernel.exception` listener) does not outlive the command.
     */
    public function testMessageOrRequestLeftOpenInsideACommandDoesNotOutliveIt(): void
    {
        $console = $this->console();
        $http    = $this->httpKernel();
        $console->addCommand($this->command('app:consume', function (): int {
            $throw = $this->throwingListener();
            $this->dispatcher->addListener(UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_RECEIVED, $throw);
            try {
                $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
            } finally {
                $this->dispatcher->removeListener(UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_RECEIVED, $throw);
            }

            return 0;
        }));
        $console->addCommand($this->command('app:crawl', function () use ($http): int {
            $throw = $this->throwingListener();
            $this->dispatcher->addListener(KernelEvents::EXCEPTION, $throw, 4096);
            try {
                $http->handle(Request::create('/no-such-route'), HttpKernelInterface::MAIN_REQUEST, true);
            } finally {
                $this->dispatcher->removeListener(KernelEvents::EXCEPTION, $throw);
            }

            return 0;
        }));

        foreach (['app:consume', 'app:crawl'] as $command) {
            self::assertNotSame(0, $console->run($this->input($command), new NullOutput()));
            $this->assertRequestDrops($command . ': nothing left in flight');
            $this->assertNextCommandIsOutermost($command);
        }
    }

    /**
     * A handler runs a command whose error listener throws, the message fails, the worker stops (or not): the next
     * main request and the next command are the outermost units again.
     */
    public function testFailedCommandInsideAFailedMessageDoesNotLeaveTheMessageInFlight(): void
    {
        $console = $this->console();
        $console->addCommand($this->command('app:fail', static function (): never {
            throw new RuntimeException('command failed');
        }));

        foreach ([true, false] as $workerStops) {
            $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
            $throw = $this->throwingListener();
            $this->dispatcher->addListener(ConsoleEvents::ERROR, $throw);
            self::assertNotSame(0, $console->run($this->input('app:fail'), new NullOutput()));
            $this->dispatcher->removeListener(ConsoleEvents::ERROR, $throw);
            $this->dispatcher->dispatch(new WorkerMessageFailedEvent(new Envelope(new stdClass()), 'async', new RuntimeException('handler failed')));
            if ($workerStops) {
                $this->dispatcher->dispatch(new WorkerStoppedEvent(new Worker([], new MessageBus())));
            }

            $label = 'failed command in a failed message, worker stops: ' . ($workerStops ? 'yes' : 'no');
            $this->assertRequestDrops($label);
            $this->assertNextCommandIsOutermost($label);
        }
    }

    /**
     * Audit NR-b: a top-level request whose `kernel.exception` listener throws (no `kernel.finish_request`) does not
     * stay "in flight": the next requests and commands are still outermost units.
     */
    public function testThrowingKernelExceptionListenerOnATopLevelRequest(): void
    {
        $http  = $this->httpKernel();
        $throw = $this->throwingListener();
        $this->dispatcher->addListener(KernelEvents::EXCEPTION, $throw, 4096);
        $request = Request::create('/no-such-route');
        try {
            $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
            self::fail('the kernel.exception listener throws');
        } catch (RuntimeException) {
        } finally {
            $this->dispatcher->removeListener(KernelEvents::EXCEPTION, $throw);
        }

        for ($i = 0; $i < 3; ++$i) {
            $this->assertRequestDrops('request ' . $i . ' after a throwing kernel.exception listener');
            $this->assertNextCommandIsOutermost('command ' . $i . ' after a throwing kernel.exception listener');
        }
    }

    /**
     * An error listener that runs another command in-process leaves a sane state.
     */
    public function testErrorListenerRunningACommandLeavesASaneState(): void
    {
        $console = $this->console();
        $console->addCommand($this->command('app:notify', static fn (): int => 0));
        $console->addCommand($this->command('app:fail', static function (): never {
            throw new RuntimeException('command failed');
        }));
        $runs     = 0;
        $listener = function () use ($console, &$runs): void {
            if ($runs++ === 0) {
                $console->run($this->input('app:notify'), new NullOutput());
            }
        };
        $this->dispatcher->addListener(ConsoleEvents::ERROR, $listener);
        self::assertNotSame(0, $console->run($this->input('app:fail'), new NullOutput()));
        $this->dispatcher->removeListener(ConsoleEvents::ERROR, $listener);

        $this->assertRequestDrops('after an error listener ran a command');
        $this->assertNextCommandIsOutermost('after an error listener ran a command');
    }

    /**
     * The private manager holds a stale copy of tenant alpha; another process renames it; the next main request must
     * show the new name (the request boundary dropped the manager) and leave no manager behind.
     */
    private function assertRequestDrops(string $label): void
    {
        $this->loadTenant('alpha');
        $name = 'Alpha ' . bin2hex(random_bytes(4));
        $this->application->getConnection()->executeStatement('UPDATE uptime_tenant SET name = ? WHERE slug = ?', [$name, 'alpha']);

        [$status, $content] = $this->serve($this->path('nowo_uptime_tenant_index'));

        self::assertSame(200, $status, $label);
        self::assertStringContainsString($name, $content, $label);
        self::assertFalse($this->bundle->isInitialized(), $label);
    }

    private function assertNextCommandIsOutermost(string $label): void
    {
        $boundaries = self::getContainer()->get(UnitOfWorkBoundaries::class);
        self::assertInstanceOf(UnitOfWorkBoundaries::class, $boundaries);
        $next  = new Command('app:next');
        $start = new ConsoleCommandEvent($next, new ArrayInput([]), new NullOutput());
        $this->dispatcher->dispatch($start, ConsoleEvents::COMMAND);
        self::assertTrue($boundaries->isOutermost($start), $label . ': the next command is the outermost unit');
        $this->loadTenant('alpha');
        $this->dispatcher->dispatch(new ConsoleTerminateEvent($next, $start->getInput(), new NullOutput(), 0), ConsoleEvents::TERMINATE);
        self::assertFalse($this->bundle->isInitialized(), $label . ': the next command drops the manager');
    }

    private function loadTenant(string $slug): Tenant
    {
        $tenant = $this->bundle->getRepository(Tenant::class)->findOneBy(['slug' => $slug]);
        self::assertInstanceOf(Tenant::class, $tenant);

        return $tenant;
    }

    /**
     * One request through the `http_kernel` service (never Kernel::handle(), which would run the services resetter).
     *
     * @return array{0: int, 1: string}
     */
    private function serve(string $path): array
    {
        $http     = $this->httpKernel();
        $request  = Request::create($path);
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $http->terminate($request, $response);

        return [$response->getStatusCode(), (string) $response->getContent()];
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function path(string $route, array $parameters = []): string
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);

        return $router->generate($route, $parameters);
    }

    private function httpKernel(): HttpKernel
    {
        $http = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);

        return $http;
    }

    private function console(): Application
    {
        self::assertNotNull(self::$kernel);
        $console = new Application(self::$kernel);
        $console->setAutoExit(false);
        $console->setCatchExceptions(true);

        return $console;
    }

    private function input(string $command): ArrayInput
    {
        $input = new ArrayInput(['command' => $command]);
        $input->setInteractive(false);

        return $input;
    }

    /**
     * @param Closure(): int $body
     */
    private function command(string $name, Closure $body): Command
    {
        return new class($name, $body) extends Command {
            /**
             * @param Closure(): int $body
             */
            public function __construct(string $name, private readonly Closure $body)
            {
                parent::__construct($name);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return ($this->body)();
            }
        };
    }

    private function throwingListener(): Closure
    {
        return static function (): never {
            throw new RuntimeException('listener failed');
        };
    }
}
