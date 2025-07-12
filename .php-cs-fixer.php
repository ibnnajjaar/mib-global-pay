<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'ordered_imports' => ['sort_algorithm' => 'length'],
        'ordered_traits' => true,
        'no_extra_blank_lines' => ['tokens' => ['use']],
    ])
    ->setFinder(
        Finder::create()
              ->in(__DIR__)
              ->exclude('vendor')
    );
