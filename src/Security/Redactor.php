<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Security;

final class Redactor
{
    private const SENSITIVE_KEYS = [
        'licence_key',
        'license_key',
        'activation_secret',
        'authorization',
        'proxy_authorization',
        'credential',
        'credentials',
        'package_token',
        'access_token',
        'refresh_token',
        'token',
        'password',
        'passwd',
        'api_key',
        'apikey',
        'client_secret',
        'private_key',
        'secret_key',
        'access_key',
        'secret',
        'cookie',
        'set_cookie',
        'signed_payload',
        'signature',
    ];

    private const SENSITIVE_QUERY_KEYS = [
        'licence_key',
        'license_key',
        'activation_secret',
        'package_token',
        'access_token',
        'refresh_token',
        'token',
        'password',
        'api_key',
        'apikey',
        'client_secret',
        'signature',
    ];

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function context(array $context): array
    {
        $redacted = [];
        foreach ($context as $key => $value) {
            $normalized = str_replace('-', '_', strtolower((string) $key));
            if ($this->sensitive($normalized)) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                $redacted[$key] = $this->context($value);
                continue;
            }
            if (is_string($value)) {
                $redacted[$key] = $this->string($value);
                continue;
            }
            $redacted[$key] = $value;
        }

        return $redacted;
    }

    private function sensitive(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($key === $sensitive || str_ends_with($key, '_' . $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function string(string $value): string
    {
        $keys = implode('|', array_map(
            static fn (string $key): string => preg_quote($key, '/'),
            self::SENSITIVE_QUERY_KEYS
        ));

        $value = preg_replace(
            '/([?&](?:' . $keys . ')=)[^&#\s]*/i',
            '$1[REDACTED]',
            $value
        ) ?? $value;

        return preg_replace(
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/-]+=*/i',
            '$1 [REDACTED]',
            $value
        ) ?? $value;
    }
}
