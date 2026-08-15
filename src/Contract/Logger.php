<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Contract;

use Zithis\LicenceClient\Enum\LogLevel;

interface Logger
{
    /** @param array<string,mixed> $context */
    public function log(LogLevel $level, string $message, array $context = []): void;
}
