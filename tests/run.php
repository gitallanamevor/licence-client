<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$classes = [
    Zithis\LicenceClient\Tests\Unit\ProtocolFixtureTest::class,
    Zithis\LicenceClient\Tests\Unit\RequestFactoryTest::class,
    Zithis\LicenceClient\Tests\Unit\LicenceClientTest::class,
    Zithis\LicenceClient\Tests\Unit\SecurityAndBoundaryTest::class,
    Zithis\LicenceClient\Tests\Unit\WordPressIntegratorBuildTest::class,
    Zithis\LicenceClient\Tests\Unit\PhpApplicationIntegratorTest::class,
];

$passed = 0;
$failed = 0;
foreach ($classes as $class) {
    $test = new $class();
    foreach ($test->run() as $result) {
        $label = $class . '::' . $result['method'];
        if ($result['passed']) {
            ++$passed;
            fwrite(STDOUT, '[PASS] ' . $label . PHP_EOL);
            continue;
        }
        ++$failed;
        fwrite(STDERR, '[FAIL] ' . $label . ' — ' . $result['message'] . PHP_EOL);
    }
}

fwrite(STDOUT, sprintf('Tests: %d passed, %d failed.%s', $passed, $failed, PHP_EOL));
exit($failed === 0 ? 0 : 1);
