<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// Downgrades the PHP 8.1 source of the 3.x line to run on PHP 7.1.
// Regenerate the php7.1 build by running: bin/rector process
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // The two enum classes and their trait are already hand-written for PHP 7.1.
    ->withSkip([
        __DIR__ . '/src/ParseErrorCode.php',
        __DIR__ . '/src/ValidationSeverity.php',
        __DIR__ . '/src/EnumEmulation.php',
    ])
    ->withDowngradeSets(php71: true);
