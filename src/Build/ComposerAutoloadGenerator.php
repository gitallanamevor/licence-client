<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Build;

interface ComposerAutoloadGenerator
{
    public function generate(string $packageRoot): void;
}
