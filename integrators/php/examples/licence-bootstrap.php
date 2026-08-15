<?php

declare(strict_types=1);

use Zithis\LicenceClient\Integrator\Php\ApplicationRuntime;
use Zithis\LicenceClient\Integrator\Php\Configuration;

require dirname(__DIR__, 5) . '/autoload.php';

$publicKey = file_get_contents('/etc/example-app/licence-authority.pem');
if (!is_string($publicKey) || trim($publicKey) === '') {
    throw new RuntimeException('The pinned LicenceServer public key is unavailable.');
}

return ApplicationRuntime::create(
    new Configuration([
        'contract_version' => 1,
        'product' => [
            'code' => 'example-app',
            'name' => 'Example App',
            'package_identifier' => 'vendor/example-app',
            'installed_version' => '1.0.0',
        ],
        'installation' => [
            'scope' => 'example-app/main',
            'environment' => 'production',
            'canonical_uri' => 'https://app.example.com',
        ],
        'authority' => [
            'id' => 'licensing.zithis.example',
            'public_keys' => [
                'release-signing-1' => $publicKey,
            ],
            'package_download_hosts' => ['licensing.zithis.example'],
            'endpoints' => [
                'activate' => 'https://licensing.zithis.example/v1/licences/activate',
                'validate' => 'https://licensing.zithis.example/v1/licences/validate',
                'deactivate' => 'https://licensing.zithis.example/v1/licences/deactivate',
                'update_check' => 'https://licensing.zithis.example/v1/updates/check',
                'package_authorisation' => 'https://licensing.zithis.example/v1/packages/authorize',
            ],
        ],
        'runtime' => [
            'state_directory' => '/var/lib/example-app/licence',
            'timeout_seconds' => 30,
            'validation_retry_seconds' => 21600,
            'update_check_seconds' => 43200,
            'lock_wait_seconds' => 0,
            'maximum_response_bytes' => 2097152,
            'maximum_package_bytes' => 536870912,
        ],
    ]),
    static function ($level, string $message, array $context): void {
        error_log(json_encode([
            'level' => $level->value,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
);
