<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])

    // Force Rector to reason as PHP 8.5.
    ->withPhpVersion(PhpVersion::PHP_85)
    // Enable PHP upgrade/deprecation sets through PHP 8.5.
    ->withPhpSets(php85: true);
