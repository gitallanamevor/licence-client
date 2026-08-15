<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Security;

use Zithis\LicenceClient\Protocol\Base64Url;

final class SignatureVerifier
{
    public function verify(string $payload, string $encodedSignature, string $publicKey): bool
    {
        if (!PublicKeyPolicy::acceptsRs256($publicKey)) {
            return false;
        }

        try {
            $signature = Base64Url::decode($encodedSignature);
        } catch (\Throwable) {
            return false;
        }

        return openssl_verify($payload, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
}
