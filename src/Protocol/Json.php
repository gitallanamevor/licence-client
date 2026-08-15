<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Protocol;

use JsonException;
use RuntimeException;

final class Json
{
    /** @param array<string,mixed> $value */
    public static function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('The protocol payload could not be encoded.', 0, $exception);
        }
    }

    /** @return array<string,mixed> */
    public static function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The protocol payload is not valid JSON.', 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('The protocol payload must be a JSON object.');
        }

        return $decoded;
    }

    private function __construct()
    {
    }
}
