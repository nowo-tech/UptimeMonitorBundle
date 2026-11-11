<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\EventSubscriber;

use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\EventSubscriber\WorkerBoundaryResetListener;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function dirname;

#[CoversClass(WorkerBoundaryResetListener::class)]
final class WorkerBoundaryResetListenerTest extends TestCase
{
    public function testTheMainRequestAndEveryOtherBoundaryDropTheBundleManager(): void
    {
        $bundle   = new UptimeEntityManager(new SqliteManagerRegistry(['default' => ['Nowo\\UptimeMonitorBundle\\Entity' => dirname(__DIR__, 3) . '/src/Entity']]));
        $listener = new WorkerBoundaryResetListener($bundle);
        $kernel   = $this->createStub(HttpKernelInterface::class);

        $bundle->live();
        $listener->onKernelRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::SUB_REQUEST));
        self::assertTrue($bundle->isInitialized(), 'a sub-request shares the main request\'s unit of work');

        $listener->onKernelRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
        self::assertFalse($bundle->isInitialized());

        $bundle->live();
        $listener->onBoundary();
        self::assertFalse($bundle->isInitialized());
    }

    public function testAnUnusedBundleManagerIsNeverBuilt(): void
    {
        (new WorkerBoundaryResetListener())->onBoundary();

        $this->addToAssertionCount(1);
    }
}
