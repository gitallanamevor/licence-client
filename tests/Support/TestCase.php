<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Tests\Support;

use RuntimeException;

abstract class TestCase
{
    public function run(): array
    {
        $results = [];
        foreach (get_class_methods($this) as $method) {
            if (!str_starts_with($method, 'test')) {
                continue;
            }
            try {
                $this->{$method}();
                $results[] = ['method' => $method, 'passed' => true, 'message' => ''];
            } catch (\Throwable $exception) {
                $results[] = ['method' => $method, 'passed' => false, 'message' => $exception->getMessage()];
            }
        }

        return $results;
    }

    protected function assertTrue(bool $condition, string $message = 'Expected condition to be true.'): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    protected function assertFalse(bool $condition, string $message = 'Expected condition to be false.'): void
    {
        $this->assertTrue(!$condition, $message);
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message !== '' ? $message : sprintf(
                'Expected %s, got %s.',
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
    }

    protected function assertNotNull(mixed $value, string $message = 'Expected a non-null value.'): void
    {
        if ($value === null) {
            throw new RuntimeException($message);
        }
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException($message !== '' ? $message : 'Expected string to contain ' . $needle . '.');
        }
    }
}
