<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Build;

use InvalidArgumentException;

final class WordPressIntegratorBuild
{
    public function __construct(
        private string $outputDirectory,
        private string $productCode,
        private string $packageIdentifier,
        private string $namespace,
        private string $clientVersion,
        private string $protocolVersion,
        private int $fileCount,
        private string $manifestSha256
    ) {
        $this->outputDirectory = rtrim(trim($this->outputDirectory), '/\\');
        $this->productCode = strtolower(trim($this->productCode));
        $this->packageIdentifier = strtolower(trim(str_replace('\\', '/', $this->packageIdentifier)));
        $this->namespace = trim($this->namespace, ' \\');
        $this->clientVersion = trim($this->clientVersion);
        $this->protocolVersion = trim($this->protocolVersion);
        $this->manifestSha256 = strtolower(trim($this->manifestSha256));

        if ($this->outputDirectory === '' || $this->productCode === '' || $this->namespace === '') {
            throw new InvalidArgumentException('The generated WordPress integrator build identity is incomplete.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*\.php$/', $this->packageIdentifier) !== 1) {
            throw new InvalidArgumentException('The generated WordPress integrator package identifier is invalid.');
        }
        if ($this->clientVersion === '' || $this->protocolVersion === '' || $this->fileCount < 1) {
            throw new InvalidArgumentException('The generated WordPress integrator build metadata is incomplete.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $this->manifestSha256) !== 1) {
            throw new InvalidArgumentException('The generated WordPress integrator manifest SHA-256 is invalid.');
        }
    }

    public function outputDirectory(): string { return $this->outputDirectory; }
    public function productCode(): string { return $this->productCode; }
    public function packageIdentifier(): string { return $this->packageIdentifier; }
    public function namespace(): string { return $this->namespace; }
    public function clientVersion(): string { return $this->clientVersion; }
    public function protocolVersion(): string { return $this->protocolVersion; }
    public function fileCount(): int { return $this->fileCount; }
    public function manifestSha256(): string { return $this->manifestSha256; }
}
