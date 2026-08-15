<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Contract;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
