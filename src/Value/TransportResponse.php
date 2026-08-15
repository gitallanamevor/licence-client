<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Value;

final class TransportResponse
{
    /** @param array<string,string|list<string>> $headers */
    public function __construct(private int $status, private array $headers, private string $body)
    {
    }

    public function status(): int { return $this->status; }
    /** @return array<string,string|list<string>> */
    public function headers(): array { return $this->headers; }
    public function body(): string { return $this->body; }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp((string) $key, $name) !== 0) {
                continue;
            }
            if (is_array($value)) {
                return $value === [] ? null : implode(', ', array_map('strval', $value));
            }

            return (string) $value;
        }

        return null;
    }
}
