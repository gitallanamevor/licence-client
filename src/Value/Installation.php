<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Value;

use InvalidArgumentException;

final class Installation
{
    public function __construct(
        private string $id,
        private string $scope,
        private string $environment,
        private ?string $canonicalUri = null
    ) {
        $this->id = strtolower(trim($this->id));
        $this->scope = strtolower(trim($this->scope));
        $this->environment = strtolower(trim($this->environment));
        $this->canonicalUri = $this->canonicalUri !== null ? rtrim(trim($this->canonicalUri), '/') : null;

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $this->id) !== 1) {
            throw new InvalidArgumentException('The installation identifier is invalid.');
        }
        if ($this->scope === '' || strlen($this->scope) > 255 || preg_match('/^[a-z0-9][a-z0-9._:\/-]*$/', $this->scope) !== 1) {
            throw new InvalidArgumentException('The installation scope is invalid.');
        }
        if (!in_array($this->environment, ['production', 'staging', 'development', 'local'], true)) {
            throw new InvalidArgumentException('The installation environment is invalid.');
        }
        if ($this->canonicalUri !== null && !$this->validCanonicalUri($this->canonicalUri)) {
            throw new InvalidArgumentException('The canonical installation URI is invalid.');
        }
    }

    public function id(): string { return $this->id; }
    public function scope(): string { return $this->scope; }
    public function environment(): string { return $this->environment; }
    public function canonicalUri(): ?string { return $this->canonicalUri; }

    /** @return array<string,string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'environment' => $this->environment,
            'canonical_uri' => $this->canonicalUri,
        ];
    }

    private function validCanonicalUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (in_array($scheme, ['http', 'https'], true)) {
            return trim((string) ($parts['host'] ?? '')) !== '';
        }

        return $scheme === 'urn' && trim((string) ($parts['path'] ?? '')) !== '';
    }
}
