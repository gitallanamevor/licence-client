<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Contract;

use DateTimeImmutable;

interface ValidationContactStore
{
    public function markValidated(string $productCode, DateTimeImmutable $validatedAt): void;
}
