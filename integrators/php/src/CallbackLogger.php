<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php;

use Closure;
use Zithis\LicenceClient\Contract\Logger;
use Zithis\LicenceClient\Enum\LogLevel;

final class CallbackLogger implements Logger
{
    private ?Closure $callback;

    public function __construct(?callable $callback = null)
    {
        $this->callback = $callback !== null ? Closure::fromCallable($callback) : null;
    }

    public function log(LogLevel $level, string $message, array $context = []): void
    {
        if ($this->callback !== null) {
            ($this->callback)($level, $message, $context);
        }
    }
}
