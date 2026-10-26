<?php

declare(strict_types = 1);

return Noldors\PhpCsFixer\Config::create(__DIR__)
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([__DIR__ . '/src', __DIR__ . '/tests'])
            ->append([__FILE__]),
    );
