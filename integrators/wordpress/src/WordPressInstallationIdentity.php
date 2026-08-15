<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator;

use RuntimeException;
use Zithis\LicenceClient\Contract\InstallationIdentity;
use Zithis\LicenceClient\Value\Installation;

final class WordPressInstallationIdentity implements InstallationIdentity
{
    private ?Installation $resolved = null;

    public function __construct(private Configuration $configuration)
    {
    }

    public function installation(): Installation
    {
        if ($this->resolved instanceof Installation) {
            return $this->resolved;
        }
        if (!function_exists('get_option') || !function_exists('add_option') || !function_exists('home_url')) {
            throw new RuntimeException('The WordPress installation identity APIs are unavailable.');
        }

        $option = $this->configuration->installationOption();
        $stored = get_option($option, null);
        if (!is_array($stored)) {
            $created = [
                'contract_version' => 1,
                'id' => $this->uuid(),
            ];
            add_option($option, $created, '', false);
            $stored = get_option($option, null);
        }
        if (!is_array($stored) || (int) ($stored['contract_version'] ?? 0) !== 1) {
            throw new RuntimeException('The stored installation identity is invalid.');
        }

        $url = rtrim((string) home_url('/'), '/');
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '') {
            throw new RuntimeException('The WordPress canonical site host is unavailable.');
        }
        if (isset($parts['port'])) {
            $host .= ':' . (int) $parts['port'];
        }
        $environment = function_exists('wp_get_environment_type')
            ? strtolower((string) wp_get_environment_type())
            : 'production';
        if (!in_array($environment, ['production', 'staging', 'development', 'local'], true)) {
            $environment = 'production';
        }

        return $this->resolved = new Installation(
            strtolower(trim((string) ($stored['id'] ?? ''))),
            $host,
            $environment,
            $url
        );
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
