<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Test;

use PHPUnit\Framework\TestCase;

// Without SearchKit (PHP 8.2: it needs 8.3 and is installed by .github/ci/search-kit.sh only there) the SearchKit
// provider test class still has to load; this stand-in lets it, and the class skips itself in setUp().
if (!class_exists(EntitySearchProviderTestCase::class)) {
    abstract class EntitySearchProviderTestCase extends TestCase
    {
    }
}
