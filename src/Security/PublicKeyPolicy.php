<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Security;

final class PublicKeyPolicy
{
    public const MINIMUM_RSA_BITS = 2048;

    public static function acceptsRs256(string $publicKey): bool
    {
        $resource = @openssl_pkey_get_public($publicKey);
        if ($resource === false) {
            return false;
        }

        $details = @openssl_pkey_get_details($resource);
        if (!is_array($details)) {
            return false;
        }

        return ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA
            && (int) ($details['bits'] ?? 0) >= self::MINIMUM_RSA_BITS;
    }

    private function __construct()
    {
    }
}
