<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\BothHosts;

/**
 * {@see DefaultDriverHostKernelTestCase} on a default manager with an explicit catch-all default driver (reproduces
 * on every DoctrineBundle version).
 */
final class CatchAllDefaultDriverHostKernelTest extends DefaultDriverHostKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return CatchAllDefaultDriverHostTestKernel::class;
    }
}
