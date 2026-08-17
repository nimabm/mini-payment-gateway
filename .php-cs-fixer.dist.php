<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/config'])
    ->append([__DIR__ . '/bin/console']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP83Migration' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'global_namespace_import' => ['import_classes' => true, 'import_functions' => false],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'phpdoc_align' => false,
        'single_line_throw' => false,
    ])
    ->setFinder($finder);
