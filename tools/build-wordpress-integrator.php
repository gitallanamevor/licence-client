<?php

declare(strict_types=1);

use Zithis\LicenceClient\Build\ComposerCommandAutoloadGenerator;
use Zithis\LicenceClient\Build\WordPressIntegratorBuilder;
use Zithis\LicenceClient\Build\WordPressIntegratorManifest;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoloader is missing. Run composer install.\n");
    exit(2);
}
require_once $autoload;

$options = getopt('', ['manifest:', 'output:', 'composer::']);
$manifestPath = is_array($options) ? trim((string) ($options['manifest'] ?? '')) : '';
$outputPath = is_array($options) ? trim((string) ($options['output'] ?? '')) : '';
$composer = is_array($options) ? trim((string) ($options['composer'] ?? 'composer')) : 'composer';
if ($manifestPath === '' || $outputPath === '') {
    fwrite(STDERR, "Usage: php tools/build-wordpress-integrator.php --manifest=/path/product.json --output=/path/licence [--composer=composer]\n");
    exit(2);
}

try {
    $manifest = WordPressIntegratorManifest::fromFile($manifestPath);
    $build = (new WordPressIntegratorBuilder(new ComposerCommandAutoloadGenerator($composer)))->build($manifest, $outputPath);
    fwrite(
        STDOUT,
        "Generated isolated standalone WordPress licence integration for {$build->productCode()} using Licence Client {$build->clientVersion()}.\n"
    );
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'WordPress integrator build failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
