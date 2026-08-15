<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Protocol;

use RuntimeException;

final class Base64Url
{
    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new RuntimeException('The base64url value is invalid.');
        }
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (!is_string($decoded)) {
            throw new RuntimeException('The base64url value could not be decoded.');
        }

        return $decoded;
    }

    private function __construct()
    {
    }
}
