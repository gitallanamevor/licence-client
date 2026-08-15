<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Tests\Support;

use RuntimeException;

/**
 * Public, test-only cryptographic material.
 *
 * This key is intentionally embedded for deterministic tests. It protects no
 * production, development, customer, or Zithis service data and MUST NEVER be
 * configured as a trusted licensing authority outside the test suite.
 */
final class TestSigningKey
{
    private const PRIVATE_KEY_BODY = 'MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQCedqvdnPR7NMh6tWaTg+XBWuj3f/v/w+yBesgyuJS3roNklmm2qolu3YBJfGfZTZtDWzXfAU6XEzRo/1GR4sGNfKj4y/3qnE0lUyB6VqX4XTXAf8YRsH5nrqAnFbSy/wSmSxJirb5D0+mQ+Kkk3diGRDAa31yYIEAUMwXJYY/btIUxcqMP5HysZkUq1+yib+aTipsZoHUkahpuBz3q8cJ//k6HOxfafUsCs1QU62rVqfp+hH7VAVsiy2W73HErJ0vXX2hg2r/On93pd+3EUSo2lMH+PS5P6laeKOrkVJB1XyjL7gekzfqUUs+RxcmJtiId6N6wEu7Qcl6NKvkOO8x/AgMBAAECggEAF4Dhh/rGNloDXFP4lWusUcwCnucgQPTV6PSFqiQ/hqj/OxWmM15DCSsYYo3tt0jv/QMTz/JZLkY+cU5hUmKQM8SoKVLUZA5v0NfuCsc8UcS9esJY5fnIHefEQFfTV/NERMgAv5smi9fsHkELkXjIK04E1o+Ho9awum5A7vgmBr+ZWiwtf1ZcaQFTNuF5THMNtN7wJlkZRjzjQMV3AbegTDZ7StMDsDTpDUzAPYmHP9SeN8yjj9whqOeT4cLeND+mEblBr9BVnsT0gfDCprwsBxRktyJgObjfH9pqBGZ4AqoYOzbRzpJeip5jrd0N6Zw/Yj7TrFAtp91tD8Xj09x7oQKBgQDXVX5ibyBKdhHok4d0Qz3WYBour6ZjGSWySwc1u1J9jgDcwAEHXkZgh5JiqRPWDttbUvX8Y0HfvsKilF05dq6OJ3AyIw2S5fci+nBkgKBiW8iLI1dkPfSZxSWtyWYFYngo/HME2WGDQH4L5kI1X/dHi7fdxbk06q34VRI/yNFM3wKBgQC8Y7qjRPnFOUygUnQjQXCOAqyPuR+YoDGM5dDN1WeD8gkLnMqR+VWuz568gSQUeIosR/bDvqTvSCNXT+3veaQEFkSVKwKWNgGYFKS74ewmA0PkERup28i0/l6QAMEMzUsUQ/a2A70hbr+YbsAeQIWjh1/55KcTeoZC3u/fsFvUYQKBgDGbdoRCyZOd475KznfQTdynQyDiQliuIGsUsdKFFxnprvUsHpCN/XSbhvPHs9QqlApT8Gt2imR7U+eUem2Uk94X49cJEEV5SRf7zgy5PTmrn2W+fJGRXFpYerewoBo5dykqD21cjwRnxSIEp9gYBaWr4G3s8R/puK5vGscrAlzNAoGBAK4YK54+W+Pa8+kkyZbbHrzd08Jt/bj6MVBYAQJ2uFbDEYDdwIXtuTT3QWZKoaEZU/df+bcjMyC9tYs2nle0PdJZEcAYIyfVeNumGCZYvfbTBaZ5+Oqb5Xr9pz3EtKK0BTIRLKlAA0QmKIEhuAE890MME7HHkG77x598johVXkeBAoGAPcWD0cMbzs57oyZB+/eTlYLMHtI/KrNAIAEsA1rmRqnh62DQTtd+YLjZe1M2CA4oxvgdkkjrImlcGNGcUSniB9DnU/Tfi+/y40RSlnM1rvB16zT31bv2/wo7jvGbvblqxXPZK12L4/EWTT67gRil9Cc4EVXVFCFYN/MoCn9+2J4=';
    private const WEAK_PUBLIC_KEY_BODY = 'MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDtldPdEqKkK+o+4rO5HfadgChON2qilcN/XYiyfGaXckW7NZsqIOUH3VVGVsj+BykSJxiMzNUx1QDRR+Wc8TyEeZPW3F8aI1h00W0oTQLjZNsYzrn1RoCs1cqcADtowcfcUjSFfbZ4jkHRNeIWwhejvHfkBs3v+aMMh48r1hBnzwIDAQAB';

    public static function privateKeyPem(): string
    {
        return self::pem('PRIVATE KEY', self::PRIVATE_KEY_BODY);
    }

    public static function weakPublicKeyPem(): string
    {
        return self::pem('PUBLIC KEY', self::WEAK_PUBLIC_KEY_BODY);
    }

    public static function publicKeyPem(): string
    {
        $resource = @openssl_pkey_get_private(self::privateKeyPem());
        if ($resource === false) {
            throw new RuntimeException('The deterministic test signing key could not be loaded.');
        }

        $details = @openssl_pkey_get_details($resource);
        $publicKey = is_array($details) ? (string) ($details['key'] ?? '') : '';
        if ($publicKey === '') {
            throw new RuntimeException('The deterministic test public key could not be derived.');
        }

        return $publicKey;
    }

    private static function pem(string $label, string $body): string
    {
        return '-----BEGIN ' . $label . "-----\n"
            . chunk_split($body, 64, "\n")
            . '-----END ' . $label . "-----\n";
    }

    private function __construct()
    {
    }
}
