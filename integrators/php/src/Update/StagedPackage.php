<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php\Update;

final class StagedPackage
{
    public function __construct(
        private string $path,
        private string $releaseId,
        private string $version,
        private string $checksum
    ) {
    }

    public function path(): string { return $this->path; }
    public function releaseId(): string { return $this->releaseId; }
    public function version(): string { return $this->version; }
    public function checksum(): string { return $this->checksum; }
}
