<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__.'/src')
    ->name('*.php');

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'strict_param' => true,
        'declare_strict_types' => true,
        'ordered_imports' => true,
        'no_unused_imports' => true,
        'no_superfluous_phpdoc_tags' => true,
        'phpdoc_align' => true,
        'phpdoc_order' => true,
        'phpdoc_separation' => true,
        'phpdoc_trim' => true,
        'single_quote' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_extra_blank_lines' => true,
        'no_blank_lines_after_class_opening' => true,
        'no_blank_lines_after_phpdoc' => true,
        'no_whitespace_in_blank_line' => true,
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw', 'continue', 'break', 'if', 'foreach', 'for', 'while', 'do', 'switch', 'try', 'declare'],
        ],
    ])
    ->setFinder($finder);
