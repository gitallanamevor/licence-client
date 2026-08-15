<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Contract;

interface ProductDescriptor
{
    public function code(): string;

    public function packageIdentifier(): string;

    public function installedVersion(): string;
}
