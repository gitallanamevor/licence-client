<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Contract;

use Zithis\LicenceClient\Value\StoredState;

interface CredentialStore
{
    public function load(string $productCode): ?StoredState;

    public function save(string $productCode, StoredState $state): void;

    public function clear(string $productCode): void;
}
