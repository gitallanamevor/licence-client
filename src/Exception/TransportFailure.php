<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Exception;

use RuntimeException;

final class TransportFailure extends RuntimeException
{
    public function __construct(private string $failureCode = 'transport_failure')
    {
        parent::__construct($failureCode);
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}
