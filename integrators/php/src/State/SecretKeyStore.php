<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php\State;

use RuntimeException;
use Zithis\LicenceClient\Protocol\Base64Url;

final class SecretKeyStore
{
    private ?string $key = null;

    public function __construct(private StateDirectory $directory, private AtomicFile $files)
    {
    }

    public function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }
        $path = $this->directory->file('secret.key');
        $encoded = $this->files->read($path, 256);
        if ($encoded === null) {
            $candidate = Base64Url::encode(random_bytes(32));
            if ($this->files->create($path, $candidate)) {
                $encoded = $candidate;
            } else {
                $encoded = $this->files->read($path, 256);
            }
        }
        if (!is_string($encoded)) {
            throw new RuntimeException('The licence encryption key is unavailable.');
        }
        $decoded = Base64Url::decode(trim($encoded));
        if (strlen($decoded) !== 32) {
            throw new RuntimeException('The licence encryption key is invalid.');
        }

        return $this->key = $decoded;
    }
}
