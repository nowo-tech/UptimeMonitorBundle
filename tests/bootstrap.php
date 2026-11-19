<?php

declare(strict_types=1);

use Doctrine\Deprecations\Deprecation;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/SearchKit/EntitySearchProviderTestCase.php';

// REQ-SF-005: the suite fails on any deprecation, with one third-party exception. doctrine/doctrine-bundle 2.19's
// ConnectionFactory calls Doctrine\DBAL\Types\Type::hasType() (deprecated in doctrine/dbal 4.6) for the types
// symfony/doctrine-bridge registers (date_point), so every kernel test would report it. Remove this line once
// DoctrineBundle resolves types through the connection. Never ignore a deprecation of UptimeMonitorBundle code here.
Deprecation::ignoreDeprecations('https://github.com/doctrine/dbal/pull/7342');
