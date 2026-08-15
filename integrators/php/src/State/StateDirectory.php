<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php\State;

use RuntimeException;
use Zithis\LicenceClient\Integrator\Php\Configuration;

final class StateDirectory
{
    private ?string $resolved = null;

    public function __construct(private Configuration $configuration)
    {
    }

    public function path(): string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }
        $base = $this->configuration->stateDirectory();
        $this->ensure($base);
        $product = $base . DIRECTORY_SEPARATOR . $this->configuration->stateNamespace();
        $this->ensure($product);

        return $this->resolved = $product;
    }

    public function file(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9._-]{1,80}$/', $name) !== 1) {
            throw new RuntimeException('The licence state filename is invalid.');
        }

        return $this->path() . DIRECTORY_SEPARATOR . $name;
    }

    private function ensure(string $directory): void
    {
        if (is_link($directory)) {
            throw new RuntimeException('The licence state directory must not be a symbolic link.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('The licence state directory could not be created.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('The licence state directory is not writable.');
        }
        @chmod($directory, 0700);
    }
}
