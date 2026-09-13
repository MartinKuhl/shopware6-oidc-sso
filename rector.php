<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// withPhpSets() with no argument picks up the minimum PHP version from
// composer.json (~8.2.0) rather than hardcoding it here, so this config
// stays correct if that constraint ever moves.
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withSkip([
        __DIR__ . '/src/Resources',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    );
