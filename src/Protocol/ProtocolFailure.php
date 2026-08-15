<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Protocol;

use RuntimeException;
use Zithis\LicenceClient\Enum\ErrorCategory;

final class ProtocolFailure extends RuntimeException
{
    private function __construct(
        private ErrorCategory $category,
        private string $errorCode,
        private bool $retryable = false
    ) {
        parent::__construct($errorCode);
    }

    public static function protocol(string $code, bool $retryable = false): self
    {
        return new self(ErrorCategory::Protocol, $code, $retryable);
    }

    public static function authority(string $code, bool $retryable = false): self
    {
        return new self(ErrorCategory::Authority, $code, $retryable);
    }

    public function category(): ErrorCategory { return $this->category; }
    public function errorCode(): string { return $this->errorCode; }
    public function retryable(): bool { return $this->retryable; }
}
