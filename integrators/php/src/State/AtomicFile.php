<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php\State;

use RuntimeException;

final class AtomicFile
{
    public function read(string $path, int $maximumBytes = 8388608): ?string
    {
        return $this->withSharedLock($path, function () use ($path, $maximumBytes): ?string {
            if (!file_exists($path)) {
                return null;
            }
            if (is_link($path) || !is_file($path)) {
                throw new RuntimeException('A licence state path is not a regular file.');
            }
            $size = filesize($path);
            if (!is_int($size) || $size < 0 || $size > $maximumBytes) {
                throw new RuntimeException('A licence state file has an invalid size.');
            }
            $content = file_get_contents($path);
            if (!is_string($content) || strlen($content) !== $size) {
                throw new RuntimeException('A licence state file could not be read.');
            }

            return $content;
        });
    }

    public function write(string $path, string $content): void
    {
        $this->withLock($path, function () use ($path, $content): void {
            if (is_link($path)) {
                throw new RuntimeException('A licence state path must not be a symbolic link.');
            }
            $temporary = dirname($path) . DIRECTORY_SEPARATOR . '.' . basename($path) . '.' . bin2hex(random_bytes(8)) . '.tmp';
            $handle = fopen($temporary, 'x+b');
            if ($handle === false) {
                throw new RuntimeException('A temporary licence state file could not be created.');
            }
            try {
                $this->writeAll($handle, $content);
                if (!fflush($handle)) {
                    throw new RuntimeException('A licence state file could not be flushed.');
                }
                if (function_exists('fsync')) {
                    @fsync($handle);
                }
                @chmod($temporary, 0600);
            } finally {
                fclose($handle);
            }
            if (PHP_OS_FAMILY === 'Windows' && file_exists($path) && !unlink($path)) {
                @unlink($temporary);
                throw new RuntimeException('The previous licence state file could not be replaced.');
            }
            if (!rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('A licence state file could not be committed.');
            }
            @chmod($path, 0600);
        });
    }

    public function create(string $path, string $content): bool
    {
        if (is_link($path)) {
            throw new RuntimeException('A licence state path must not be a symbolic link.');
        }
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            return false;
        }
        try {
            $this->writeAll($handle, $content);
            if (!fflush($handle)) {
                throw new RuntimeException('A licence state file could not be flushed.');
            }
            if (function_exists('fsync')) {
                @fsync($handle);
            }
            @chmod($path, 0600);
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($path);
            throw $exception;
        }
        fclose($handle);

        return true;
    }

    public function delete(string $path): void
    {
        $this->withLock($path, function () use ($path): void {
            if (!file_exists($path)) {
                return;
            }
            if (is_link($path) || !is_file($path) || !unlink($path)) {
                throw new RuntimeException('A licence state file could not be deleted.');
            }
        });
    }

    public function withLock(string $path, callable $callback): mixed
    {
        $lockPath = $path . '.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('A licence state lock must not be a symbolic link.');
        }
        $handle = fopen($lockPath, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('A licence state lock could not be acquired.');
        }
        @chmod($lockPath, 0600);
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function withSharedLock(string $path, callable $callback): mixed
    {
        $lockPath = $path . '.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('A licence state lock must not be a symbolic link.');
        }
        $handle = fopen($lockPath, 'c+b');
        if ($handle === false || !flock($handle, LOCK_SH)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('A licence state lock could not be acquired.');
        }
        @chmod($lockPath, 0600);
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function writeAll($handle, string $content): void
    {
        $offset = 0;
        $length = strlen($content);
        while ($offset < $length) {
            $written = fwrite($handle, substr($content, $offset));
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException('A licence state file could not be written.');
            }
            $offset += $written;
        }
    }
}
