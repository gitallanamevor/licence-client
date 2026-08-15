<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Composer autoloader is missing. Run composer install.');
}
require_once $autoload;

require_once __DIR__ . '/Support/WordPressStubs.php';
require_once __DIR__ . '/Support/Fakes.php';
