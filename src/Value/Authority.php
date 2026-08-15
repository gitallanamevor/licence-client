<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Value;

use InvalidArgumentException;
use Zithis\LicenceClient\Security\PublicKeyPolicy;

final class Authority
{
    /** @var array<string,string> */
    private array $publicKeys;

    /** @param array<string,string> $publicKeys */
    public function __construct(private string $id, array $publicKeys)
    {
        $this->id = strtolower(trim($this->id));
        if (preg_match('/^[a-z][a-z0-9.-]{2,127}$/', $this->id) !== 1) {
            throw new InvalidArgumentException('The LicenceServer authority identifier is invalid.');
        }

        $normalized = [];
        foreach ($publicKeys as $keyId => $publicKey) {
            $keyId = strtolower(trim((string) $keyId));
            $publicKey = trim($publicKey);
            if (preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $keyId) !== 1 || !PublicKeyPolicy::acceptsRs256($publicKey)) {
                throw new InvalidArgumentException('A pinned authority key is invalid.');
            }
            $normalized[$keyId] = $publicKey;
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('At least one authority public key must be pinned.');
        }
        $this->publicKeys = $normalized;
    }

    public function id(): string { return $this->id; }

    public function publicKey(string $keyId): ?string
    {
        return $this->publicKeys[strtolower(trim($keyId))] ?? null;
    }
}
