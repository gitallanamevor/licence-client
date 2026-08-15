<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php;

use Zithis\LicenceClient\Contract\ProductDescriptor as ProductDescriptorContract;

final class ProductDescriptor implements ProductDescriptorContract
{
    public function __construct(private Configuration $configuration)
    {
    }

    public function code(): string { return $this->configuration->productCode(); }
    public function packageIdentifier(): string { return $this->configuration->packageIdentifier(); }
    public function installedVersion(): string { return $this->configuration->installedVersion(); }
}
