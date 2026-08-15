<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Contract;

use Zithis\LicenceClient\Value\Installation;

interface InstallationIdentity
{
    public function installation(): Installation;
}
