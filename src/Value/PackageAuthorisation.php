<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Value;

use DateTimeImmutable;
use InvalidArgumentException;

final class PackageAuthorisation
{
    public function __construct(
        private string $releaseId,
        private string $downloadUri,
        private string $token,
        private DateTimeImmutable $expiresAt,
        private string $checksum
    ) {
        $this->releaseId = trim($this->releaseId);
        $this->downloadUri = trim($this->downloadUri);
        $this->token = trim($this->token);
        $this->checksum = strtolower(trim($this->checksum));

        $parts = parse_url($this->downloadUri);
        if ($this->releaseId === ''
            || !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The package download authority is invalid.');
        }
        if (preg_match('/^[0-9A-Za-z._~-]{32,2048}$/', $this->token) !== 1 || preg_match('/^[a-f0-9]{64}$/', $this->checksum) !== 1) {
            throw new InvalidArgumentException('The package token or checksum is invalid.');
        }
    }

    public function releaseId(): string { return $this->releaseId; }
    public function downloadUri(): string { return $this->downloadUri; }
    public function token(): string { return $this->token; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function checksum(): string { return $this->checksum; }
}
