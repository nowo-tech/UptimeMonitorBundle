<?php

declare(strict_types=1);

// SearchKit is optional and installed only on PHP 8.3+ (.github/ci/search-kit.sh): without it, its provider and
// tests cannot be analysed, so they are left out. PHPStan merges this into phpstan.neon.dist.
$installed = class_exists('Nowo\\SearchKitBundle\\Provider\\Entity\\EntitySearchDefinition');

return [
    'parameters' => [
        'excludePaths' => [
            'analyse' => array_merge([__DIR__ . '/demo'], $installed ? [] : [__DIR__ . '/src/Search/SearchKit', __DIR__ . '/tests/SearchKit']),
            // Stand-in for SearchKit's test case when SearchKit is absent; scanned, it would shadow the real class.
            'analyseAndScan' => [__DIR__ . '/tests/Support/SearchKit'],
        ],
    ],
];
