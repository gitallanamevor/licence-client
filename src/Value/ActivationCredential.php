<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Value;

use InvalidArgumentException;

final class ActivationCredential
{
    public function __construct(private string $id, private string $secret)
    {
        $this->id = strtolower(trim($this->id));
        $this->secret = trim($this->secret);

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $this->id) !== 1) {
            throw new InvalidArgumentException('The activation identifier is invalid.');
        }
        if (preg_match('/^[0-9A-Za-z_-]{43,128}$/', $this->secret) !== 1) {
            throw new InvalidArgumentException('The activation secret is invalid.');
        }
    }

    public function id(): string { return $this->id; }
    public function secret(): string { return $this->secret; }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return ['activation_id' => $this->id, 'activation_secret' => $this->secret];
    }
}
