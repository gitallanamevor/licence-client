<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Value;

use InvalidArgumentException;
use Zithis\LicenceClient\Enum\Operation;

final class EndpointSet
{
    /** @param array<string,string> $endpoints */
    public function __construct(private array $endpoints)
    {
        foreach (Operation::cases() as $operation) {
            $uri = trim((string) ($this->endpoints[$operation->value] ?? ''));
            if (!$this->validHttpUri($uri)) {
                throw new InvalidArgumentException('The ' . $operation->value . ' endpoint must be an absolute HTTP or HTTPS URI.');
            }
            $this->endpoints[$operation->value] = $uri;
        }
    }

    public function for(Operation $operation): string
    {
        return $this->endpoints[$operation->value];
    }

    private function validHttpUri(string $uri): bool
    {
        $parts = parse_url($uri);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && trim((string) ($parts['host'] ?? '')) !== ''
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
    }
}
