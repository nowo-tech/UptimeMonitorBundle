<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\BothHosts;

/**
 * {@see DefaultDriverHostKernelTestCase} on a default manager without mappings (DoctrineBundle 3.4 auto-discovery).
 */
final class DefaultDriverHostKernelTest extends DefaultDriverHostKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DefaultDriverHostTestKernel::class;
    }
}
