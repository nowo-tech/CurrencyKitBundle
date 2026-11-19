<?php

declare(strict_types=1);

/**
 * Rector configuration for Currency Kit Bundle.
 *
 * @see https://getrector.com/documentation
 */
use Rector\Config\RectorConfig;
use Rector\Symfony\Symfony73\Rector\Class_\GetFiltersAndFunctionsToAsTwigAttributeRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withComposerBased(symfony: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        // The Twig extension stays an AbstractExtension: hosts on Twig < 3.21 keep working.
        GetFiltersAndFunctionsToAsTwigAttributeRector::class,
        // Its DBAL 3 skip is "always true" for the DBAL 4 used by Rector, but not on the DBAL 3 CI step.
        __DIR__ . '/tests/Integration/Database/SchemaTest.php',
        __DIR__ . '/vendor',
    ]);
