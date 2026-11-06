<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\EventSubscriber;

use Nowo\UptimeMonitorBundle\EventSubscriber\TenantPathGuardSubscriber;
use Nowo\UptimeMonitorBundle\Routing\TenantPaths;
use Nowo\UptimeMonitorBundle\Routing\UptimeMonitorRouteLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[CoversClass(TenantPathGuardSubscriber::class)]
final class TenantPathGuardSubscriberTest extends TestCase
{
    public function testSubscribesAfterTheRouter(): void
    {
        self::assertSame([KernelEvents::REQUEST => ['onKernelRequest', 8]], TenantPathGuardSubscriber::getSubscribedEvents());
    }

    public function testEachTenantIsServedUnderItsOwnPathOnly(): void
    {
        $guard = $this->guard();

        $guard->onKernelRequest($this->event('', 'acme'));
        $guard->onKernelRequest($this->event('personal_', 'user-1'));
        $guard->onKernelRequest($this->event(null, 'user-1'));
        $guard->onKernelRequest($this->event('', null));

        $this->expectException(NotFoundHttpException::class);
        $guard->onKernelRequest($this->event('', 'user-1'));
    }

    public function testAnotherSlugUnderACopyIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->guard()->onKernelRequest($this->event('personal_', 'acme'));
    }

    private function guard(): TenantPathGuardSubscriber
    {
        return new TenantPathGuardSubscriber(new TenantPaths([
            ['slug_prefix' => 'user-', 'path' => '/uptime/{tenantSlug}', 'name_prefix' => 'personal_', 'slug_requirement' => 'user-.+'],
        ]));
    }

    private function event(?string $served, ?string $slug): RequestEvent
    {
        $request = new Request();
        if ($served !== null) {
            $request->attributes->set(UptimeMonitorRouteLoader::TENANT_PATH_ATTRIBUTE, $served);
        }
        if ($slug !== null) {
            $request->attributes->set('tenantSlug', $slug);
        }

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
