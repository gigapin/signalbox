<?php

/**
 * Apply a single PHP style baseline without deleting explanatory documentation.
 */

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

/** @var Finder $finder Application-owned PHP sources, excluding dependencies and generated views. */
$finder = Finder::create()
    ->in([__DIR__.'/app', __DIR__.'/bootstrap', __DIR__.'/config', __DIR__.'/database', __DIR__.'/routes', __DIR__.'/tests'])
    ->exclude(['cache'])
    ->append([__FILE__, __DIR__.'/artisan', __DIR__.'/public/index.php']);

return (new Config())
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'array_indentation' => true,
        'method_chaining_indentation' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
    ])
    ->setRiskyAllowed(false)
    ->setFinder($finder);
