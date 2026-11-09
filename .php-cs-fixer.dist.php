<?php

declare(strict_types=1);

// The code style: PER Coding Style (the PHP-FIG's successor to PSR-12), with PHP 8.2's syntax.
// `composer lint` checks it, `composer format` fixes it.

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/config', __DIR__ . '/scripts', __DIR__ . '/src', __DIR__ . '/tests'])
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS3x0' => true,
        '@PER-CS3x0:risky' => true,
        '@PHP8x2Migration' => true,
        '@PHP8x2Migration:risky' => true,
        'declare_strict_types' => true,
        'native_constant_invocation' => ['scope' => 'namespaced', 'strict' => true],
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'match', 'parameters']],
    ])
    ->setFinder($finder);
