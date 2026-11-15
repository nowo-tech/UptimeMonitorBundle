<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

use function method_exists;

/**
 * The schema tool of the tests, on Doctrine ORM's schema editor API where the ORM has it (3.8+: not using it is
 * deprecated, and the suite fails on any deprecation).
 */
final class TestSchemaTool
{
    public static function of(EntityManagerInterface $em): SchemaTool
    {
        $configuration = $em->getConfiguration();
        // doctrine/orm ^3.7 is allowed: 3.7 has no schema editor API.
        // @phpstan-ignore function.alreadyNarrowedType
        if (method_exists($configuration, 'setUseSchemaEditorApi')) {
            $configuration->setUseSchemaEditorApi(true);
        }

        return new SchemaTool($em);
    }
}
