<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Value;

use InvalidArgumentException;
use Zithis\LicenceClient\Contract\ProductDescriptor;

final class Product
{
    public function __construct(
        private string $code,
        private string $packageIdentifier,
        private string $installedVersion
    ) {
        $this->code = strtolower(trim($this->code));
        $this->packageIdentifier = trim($this->packageIdentifier);
        $this->installedVersion = trim($this->installedVersion);

        if (preg_match('/^[a-z][a-z0-9-]{2,63}$/', $this->code) !== 1) {
            throw new InvalidArgumentException('The software product code is invalid.');
        }
        if ($this->packageIdentifier === '' || strlen($this->packageIdentifier) > 191) {
            throw new InvalidArgumentException('The package identifier is invalid.');
        }
        if ($this->installedVersion === '' || strlen($this->installedVersion) > 64 || preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]*$/', $this->installedVersion) !== 1) {
            throw new InvalidArgumentException('The installed product version is invalid.');
        }
    }

    public static function fromDescriptor(ProductDescriptor $descriptor): self
    {
        return new self($descriptor->code(), $descriptor->packageIdentifier(), $descriptor->installedVersion());
    }

    public function code(): string { return $this->code; }
    public function packageIdentifier(): string { return $this->packageIdentifier; }
    public function installedVersion(): string { return $this->installedVersion; }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'package_identifier' => $this->packageIdentifier,
            'installed_version' => $this->installedVersion,
        ];
    }
}
