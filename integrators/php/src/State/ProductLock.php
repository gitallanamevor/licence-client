<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php\State;

use RuntimeException;

final class ProductLock
{
    public function __construct(private StateDirectory $directory)
    {
    }

    public function run(string $name, int $waitSeconds, callable $callback): mixed
    {
        $path = $this->directory->file($name . '.lock');
        if (is_link($path)) {
            throw new RuntimeException('The application licence lock must not be a symbolic link.');
        }
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('The application licence lock could not be opened.');
        }
        @chmod($path, 0600);
        $deadline = microtime(true) + max(0, $waitSeconds);
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                try {
                    return $callback();
                } finally {
                    flock($handle, LOCK_UN);
                    fclose($handle);
                }
            }
            if ($waitSeconds === 0 || microtime(true) >= $deadline) {
                fclose($handle);

                return null;
            }
            usleep(50000);
        } while (true);
    }
}
