<?php

declare(strict_types=1);

// SearchKit is optional and installed only on PHP 8.3+ (.github/ci/search-kit.sh): without it, its provider and
// tests cannot be analysed, so they are left out. PHPStan merges this into phpstan.neon.dist.
$installed = class_exists('Nowo\\SearchKitBundle\\Provider\\Entity\\EntitySearchDefinition');

return [
    'parameters' => [
        'excludePaths' => [
            'analyse' => $installed ? [] : ['src/Search/SearchKit/*', 'tests/SearchKit/*'],
        ],
    ],
];
