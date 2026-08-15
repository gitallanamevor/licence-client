<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Value;

use Zithis\LicenceClient\Enum\ErrorCategory;

final class ClientError
{
    public function __construct(
        private ErrorCategory $category,
        private string $code,
        private bool $retryable = false,
        private ?string $requestId = null
    ) {
    }

    public function category(): ErrorCategory { return $this->category; }
    public function code(): string { return $this->code; }
    public function retryable(): bool { return $this->retryable; }
    public function requestId(): ?string { return $this->requestId; }
}
