<?php

$finder = PhpCsFixer\Finder::create()
  ->in(__DIR__)
  ->exclude('vendor')
  ->exclude('mixin')
  ->name('*.php');

$config = new PhpCsFixer\Config();

return $config
  ->setRules([
    '@PSR12' => true,
    'indentation_type' => true,
    'array_indentation' => true,
    'array_syntax' => ['syntax' => 'short'],
    'no_unused_imports' => true,
    'ordered_imports' => ['sort_algorithm' => 'alpha'],
    'single_quote' => true,
    'no_trailing_comma_in_singleline' => true,
    'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
    'concat_space' => ['spacing' => 'one'],
    'braces_position' => [
      'classes_opening_brace' => 'same_line',
      'functions_opening_brace' => 'same_line',
    ],
    'lowercase_static_reference' => true,
    'constant_case' => ['case' => 'lower'],
  ])
  ->setIndent('  ')
  ->setLineEnding("\n")
  ->setFinder($finder);
