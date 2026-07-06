<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Support;

use ReflectionProperty;

trait EntityIdTrait
{
    protected function setEntityId(object $entity, int $id): void
    {
        $reflection = new ReflectionProperty($entity, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
