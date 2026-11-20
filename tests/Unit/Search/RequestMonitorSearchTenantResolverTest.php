<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Search;

use Nowo\UptimeMonitorBundle\Search\RequestMonitorSearchTenantResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(RequestMonitorSearchTenantResolver::class)]
final class RequestMonitorSearchTenantResolverTest extends TestCase
{
    public function testRequestSlugThenDefaultThenNone(): void
    {
        $stack    = new RequestStack();
        $resolver = new RequestMonitorSearchTenantResolver($stack, ['default_tenant' => 'main']);

        self::assertSame('main', $resolver->resolve('u'));

        $stack->push(new Request([], [], ['tenantSlug' => 'acme']));
        self::assertSame('acme', $resolver->resolve('u'));

        $stack->pop();
        self::assertSame('main', $resolver->resolve('u'));
        self::assertSame('main', (new RequestMonitorSearchTenantResolver($stack))->resolve('u'));
        self::assertNull((new RequestMonitorSearchTenantResolver($stack, ['default_tenant' => '']))->resolve('u'));
        // 1.33: the same tenant as a list.
        self::assertSame(['main'], $resolver->resolveTenants('u'));
        self::assertSame([], (new RequestMonitorSearchTenantResolver($stack, ['default_tenant' => '']))->resolveTenants('u'));
    }
}
