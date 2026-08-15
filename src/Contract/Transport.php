<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Contract;

use Zithis\LicenceClient\Value\TransportRequest;
use Zithis\LicenceClient\Value\TransportResponse;

interface Transport
{
    public function send(TransportRequest $request): TransportResponse;
}
