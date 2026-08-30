<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
;

// @Symfony over PSR-12, which is what a published composer package is read
// as. The repo's other PHP project keeps a 2-space house style; this one
// does not, because contributors and packagist readers expect the default.
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        // Every file states its typing, and a missing declare is a real
        // behaviour difference rather than a style one.
        'declare_strict_types' => true,
        // \strlen over strlen: the global lookup is skipped and, more to
        // the point, a function shadowed in the namespace cannot silently
        // take over.
        'native_function_invocation' => ['include' => ['@all'], 'scope' => 'namespaced'],
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'phpdoc_order' => true,
        'phpdoc_separation' => true,
    ])
    ->setFinder($finder)
;
