<?php

declare(strict_types=1);

use CrazyGoat\WorkermanBundle\Tools\PhpCsFixer\NoNarrativeCommentsFixer;

require_once __DIR__ . '/tools/php-cs-fixer/autoload.php';

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->in(__DIR__ . '/benchmarks')
    ->in(__DIR__ . '/bin')
    ->in(__DIR__ . '/tools')
    ->in(__DIR__ . '/e2e')
    ->exclude(['vendor', 'var', 'build'])
    ->append([__DIR__ . '/e2e/console', __FILE__, __DIR__ . '/rector.php'])
;

$rules = [
    '@PER-CS2x0' => true,

    '@PER-CS2x0:risky' => true,

    'array_syntax' => ['syntax' => 'short'],

    'align_multiline_comment' => true,

    'cast_spaces' => true,

    'no_empty_comment' => true,

    'no_unused_imports' => true,

    'phpdoc_scalar' => true,

    'phpdoc_single_line_var_spacing' => true,

    'phpdoc_trim' => true,

    'phpdoc_var_annotation_correct_order' => true,

    'no_empty_statement' => true,

    'no_spaces_around_offset' => true,

    'declare_strict_types' => true,

    'strict_comparison' => true,

    'ordered_imports' => true,

    'get_class_to_class_keyword' => true,

    'no_superfluous_phpdoc_tags' => true,

    'trailing_comma_in_multiline' => [
        'after_heredoc' => true,
        'elements' => ['arrays', 'match', 'arguments', 'parameters'],
    ],

    'single_line_empty_body' => false,

    NoNarrativeCommentsFixer::NAME => true,

    'no_empty_phpdoc' => true,
];

return (new PhpCsFixer\Config())
    ->setCacheFile(__DIR__ . '/var/.php-cs-fixer.cache')
    ->registerCustomFixers([new NoNarrativeCommentsFixer()])
    ->setFinder($finder)
    ->setRules($rules)
    ->setRiskyAllowed(true)
    ->setUnsupportedPhpVersionAllowed(true)
;
