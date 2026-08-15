<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php\Update;

interface PackageTransport
{
    public function download(string $uri, string $destination, int $timeoutSeconds, int $maximumBytes): void;
}
