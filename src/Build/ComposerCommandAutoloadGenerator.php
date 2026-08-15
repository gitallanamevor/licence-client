<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Build;

use RuntimeException;

final class ComposerCommandAutoloadGenerator implements ComposerAutoloadGenerator
{
    public function __construct(private string $composerExecutable = 'composer')
    {
        $this->composerExecutable = trim($this->composerExecutable);
        if ($this->composerExecutable === '') {
            throw new RuntimeException('The Composer executable is required for standalone WordPress integrator builds.');
        }
    }

    public function generate(string $packageRoot): void
    {
        $root = realpath($packageRoot);
        if (!is_string($root) || !is_dir($root) || !is_writable($root)) {
            throw new RuntimeException('The standalone WordPress integrator package root is unavailable for Composer autoload generation.');
        }
        if (!function_exists('proc_open')) {
            throw new RuntimeException('Standalone WordPress integrator generation requires PHP proc_open() for Composer autoload generation.');
        }

        $arguments = [
            'dump-autoload',
            '--no-dev',
            '--optimize',
            '--no-interaction',
            '--no-scripts',
            '--no-plugins',
        ];
        $command = $this->command($arguments);
        $stdout = tempnam(dirname($root), 'zlc-composer-out-');
        $stderr = tempnam(dirname($root), 'zlc-composer-err-');
        if (!is_string($stdout) || !is_string($stderr)) {
            throw new RuntimeException('Composer autoload generation output files could not be allocated.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdout, 'w'],
            2 => ['file', $stderr, 'w'],
        ];
        $pipes = [];

        try {
            $process = proc_open($command, $descriptors, $pipes, $root, null, ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new RuntimeException('Composer autoload generation process could not be started.');
            }
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }

            $exitCode = proc_close($process);
            if ($exitCode !== 0) {
                $message = trim((string) file_get_contents($stderr));
                if ($message === '') {
                    $message = trim((string) file_get_contents($stdout));
                }
                $message = preg_replace('/\s+/', ' ', $message) ?: $message;
                if (strlen($message) > 1600) {
                    $message = substr($message, 0, 1600) . '…';
                }

                throw new RuntimeException(
                    'Composer autoload generation for the standalone licence runtime failed.'
                    . ($message !== '' ? ' ' . $message : '')
                );
            }
        } finally {
            @unlink($stdout);
            @unlink($stderr);
        }
    }

    /** @param list<string> $arguments @return list<string> */
    private function command(array $arguments): array
    {
        $configured = trim($this->composerExecutable, " \t\n\r\0\x0B\"");
        $resolved = $this->resolveExecutable($configured);
        if ($resolved === null) {
            throw new RuntimeException(
                'Composer executable "' . $configured . '" could not be resolved. '
                . 'Install Composer, add it to PATH, or provide the Composer executable explicitly.'
            );
        }

        $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
        if ($extension === 'phar' || $extension === 'php') {
            return array_merge([PHP_BINARY, $resolved], $arguments);
        }

        if (PHP_OS_FAMILY === 'Windows' && ($extension === 'bat' || $extension === 'cmd')) {
            $phar = dirname($resolved) . DIRECTORY_SEPARATOR . pathinfo($resolved, PATHINFO_FILENAME) . '.phar';
            if (!is_file($phar)) {
                $phar = dirname($resolved) . DIRECTORY_SEPARATOR . 'composer.phar';
            }
            if (is_file($phar)) {
                $realPhar = realpath($phar);
                return array_merge([PHP_BINARY, is_string($realPhar) ? $realPhar : $phar], $arguments);
            }

            throw new RuntimeException(
                'The Windows Composer wrapper was found, but composer.phar was not available beside it. '
                . 'Provide the composer.phar path explicitly.'
            );
        }

        return array_merge([$resolved], $arguments);
    }

    private function resolveExecutable(string $configured): ?string
    {
        if (is_file($configured)) {
            $real = realpath($configured);
            return is_string($real) ? $real : $configured;
        }

        if (str_contains($configured, '/') || str_contains($configured, '\\')) {
            return null;
        }

        $path = getenv('PATH');
        if (!is_string($path) || trim($path) === '') {
            return null;
        }

        $names = [$configured];
        if (PHP_OS_FAMILY === 'Windows' && pathinfo($configured, PATHINFO_EXTENSION) === '') {
            // Prefer composer.phar so Windows builds bypass cmd.exe and batch-file quoting entirely.
            $names = [
                $configured . '.phar',
                $configured . '.bat',
                $configured . '.cmd',
                $configured . '.exe',
                $configured,
            ];
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $directory = trim($directory, " \t\n\r\0\x0B\"");
            if ($directory === '') {
                continue;
            }
            foreach ($names as $name) {
                $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
                if (!is_file($candidate)) {
                    continue;
                }
                $real = realpath($candidate);
                return is_string($real) ? $real : $candidate;
            }
        }

        return null;
    }
}
