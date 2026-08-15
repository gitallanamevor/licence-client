<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php;

use DateTimeImmutable;
use DateTimeZone;
use Zithis\LicenceClient\Contract\Clock;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
