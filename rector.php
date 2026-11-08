<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Ternary\TernaryToNullsafeCoalesceRector;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
use Rector\Instanceof_\Rector\Ternary\FlipNegatedTernaryInstanceofRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;

/*
 * Development tooling only; it is not a consumer upgrade set (DESIGN-v2.md §12 Q8).
 *
 * The PHP level comes from composer.json. Formatting is left to Pint. These stay off on purpose:
 * - coding style and name importing: Pint owns them;
 * - naming and strict booleans: they change public names or truthiness checks in payment code;
 * - instanceof and privatization rules: `=== null` checks and static helpers read better here;
 * - nullsafe coalesce: `??` chains would hide a broken property path from PHPStan;
 * - arrow function return types: noise on callbacks whose return value is ignored;
 * - removing `@var` annotations: it deletes ones PHPStan needs at level max.
 *
 * Tests are not processed: Pest closures gain nothing from these rules.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/config',
        __DIR__ . '/database',
    ])
    ->withCache(__DIR__ . '/build/rector')
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withSkip([
        RemoveNonExistingVarAnnotationRector::class,
        ExplicitNullableObjectInstanceRector::class,
        FlipNegatedTernaryInstanceofRector::class,
        LocallyCalledStaticMethodToNonStaticRector::class,
        TernaryToNullsafeCoalesceRector::class,
        AddArrowFunctionReturnTypeRector::class,
    ]);
