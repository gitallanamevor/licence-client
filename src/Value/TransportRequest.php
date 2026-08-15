<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Value;

use Zithis\LicenceClient\Enum\Operation;

final class TransportRequest
{
    /** @param array<string,string> $headers */
    public function __construct(
        private Operation $operation,
        private string $uri,
        private array $headers,
        private string $body,
        private string $requestId,
        private string $nonce
    ) {
    }

    public function operation(): Operation { return $this->operation; }
    public function method(): string { return 'POST'; }
    public function uri(): string { return $this->uri; }
    /** @return array<string,string> */
    public function headers(): array { return $this->headers; }
    public function body(): string { return $this->body; }
    public function requestId(): string { return $this->requestId; }
    public function nonce(): string { return $this->nonce; }
}
