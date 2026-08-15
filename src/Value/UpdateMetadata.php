<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Value;

use InvalidArgumentException;

final class UpdateMetadata
{
    public function __construct(
        private string $releaseId,
        private string $version,
        private string $packageIdentifier,
        private string $checksumAlgorithm,
        private string $checksum,
        private ?string $minimumPhp,
        private ?string $minimumRuntime,
        private ?string $publishedAt
    ) {
        $this->releaseId = trim($this->releaseId);
        $this->version = trim($this->version);
        $this->packageIdentifier = trim($this->packageIdentifier);
        $this->checksumAlgorithm = strtolower(trim($this->checksumAlgorithm));
        $this->checksum = strtolower(trim($this->checksum));

        if ($this->releaseId === '' || strlen($this->releaseId) > 128) {
            throw new InvalidArgumentException('The release identifier is invalid.');
        }
        if ($this->version === '' || strlen($this->version) > 64) {
            throw new InvalidArgumentException('The release version is invalid.');
        }
        if ($this->packageIdentifier === '' || strlen($this->packageIdentifier) > 191) {
            throw new InvalidArgumentException('The update package identifier is invalid.');
        }
        if ($this->checksumAlgorithm !== 'sha256' || preg_match('/^[a-f0-9]{64}$/', $this->checksum) !== 1) {
            throw new InvalidArgumentException('The release checksum is invalid.');
        }
    }

    public function releaseId(): string { return $this->releaseId; }
    public function version(): string { return $this->version; }
    public function packageIdentifier(): string { return $this->packageIdentifier; }
    public function checksumAlgorithm(): string { return $this->checksumAlgorithm; }
    public function checksum(): string { return $this->checksum; }
    public function minimumPhp(): ?string { return $this->minimumPhp; }
    public function minimumRuntime(): ?string { return $this->minimumRuntime; }
    public function publishedAt(): ?string { return $this->publishedAt; }
}
