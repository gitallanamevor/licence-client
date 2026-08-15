<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php\State;

use RuntimeException;
use Zithis\LicenceClient\Contract\InstallationIdentity;
use Zithis\LicenceClient\Integrator\Php\Configuration;
use Zithis\LicenceClient\Value\Installation;

final class InstallationIdentityStore implements InstallationIdentity
{
    private ?Installation $installation = null;

    public function __construct(
        private Configuration $configuration,
        private StateDirectory $directory,
        private AtomicFile $files
    ) {
    }

    public function installation(): Installation
    {
        if ($this->installation !== null) {
            return $this->installation;
        }
        $path = $this->directory->file('installation.json');
        $content = $this->files->read($path, 4096);
        if ($content === null) {
            $payload = $this->payload($this->uuid());
            $candidate = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($this->files->create($path, $candidate)) {
                $content = $candidate;
            } else {
                $content = $this->files->read($path, 4096);
            }
        }
        if (!is_string($content)) {
            throw new RuntimeException('The application installation identity is unavailable.');
        }
        $payload = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($payload)
            || (int) ($payload['contract_version'] ?? 0) !== 1
            || !hash_equals($this->configuration->productCode(), (string) ($payload['product_code'] ?? ''))
            || !hash_equals($this->configuration->packageIdentifier(), (string) ($payload['package_identifier'] ?? ''))) {
            throw new RuntimeException('The application installation identity does not match this product.');
        }

        return $this->installation = new Installation(
            (string) ($payload['installation_id'] ?? ''),
            $this->configuration->installationScope(),
            $this->configuration->environment(),
            $this->configuration->canonicalUri()
        );
    }

    /** @return array<string,mixed> */
    private function payload(string $id): array
    {
        return [
            'contract_version' => 1,
            'installation_id' => $id,
            'product_code' => $this->configuration->productCode(),
            'package_identifier' => $this->configuration->packageIdentifier(),
        ];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
