<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([
        __DIR__ . '/demo',
        // 1.35: the constructor parameter keeps its name `$monitor` (named-argument BC) while the property behind the
        // deprecated `monitor` accessor has another name, so promotion would rename the parameter.
        ClassPropertyAssignToConstructorPromotionRector::class => [__DIR__ . '/src/Event/MonitorQuotaCheckEvent.php'],
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
