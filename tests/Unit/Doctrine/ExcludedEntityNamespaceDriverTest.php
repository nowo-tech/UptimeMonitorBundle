<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Persistence\Mapping\MappingException;
use Nowo\UptimeMonitorBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Tests\Fixtures\Host\Entity\HostNote;
use PHPUnit\Framework\TestCase;

final class ExcludedEntityNamespaceDriverTest extends TestCase
{
    public function testMapsNothing(): void
    {
        $driver = new ExcludedEntityNamespaceDriver();

        self::assertTrue($driver->isTransient(Monitor::class));
        self::assertSame([], $driver->getAllClassNames());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(Monitor::class);
        $driver->loadMetadataForClass(Monitor::class, new ClassMetadata(Monitor::class));
    }

    /**
     * A chain asks its namespace drivers before its default driver: a default driver that claims every entity class
     * no longer claims the bundle entities.
     */
    public function testWinsOverTheDefaultDriverOfAChain(): void
    {
        $catchAll = $this->createStub(MappingDriver::class);
        $catchAll->method('isTransient')->willReturn(false);
        $chain = new MappingDriverChain();
        $chain->setDefaultDriver($catchAll);
        self::assertFalse($chain->isTransient(Monitor::class));

        $chain->addDriver(new ExcludedEntityNamespaceDriver(), 'Nowo\UptimeMonitorBundle\Entity');

        self::assertTrue($chain->isTransient(Monitor::class));
        self::assertTrue($chain->isTransient(Tenant::class));
        self::assertFalse($chain->isTransient(HostNote::class), 'other classes still go to the default driver');
    }
}
