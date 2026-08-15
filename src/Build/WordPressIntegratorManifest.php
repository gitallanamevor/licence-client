<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Build;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class WordPressIntegratorManifest
{
    private const ENDPOINTS = [
        'activate',
        'validate',
        'deactivate',
        'update_check',
        'package_authorisation',
    ];

    /** @param array<string,mixed> $payload */
    private function __construct(
        private array $payload,
        private string $publicKey
    ) {
    }

    public static function fromFile(string $path): self
    {
        $path = self::absolutePath($path);
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('The WordPress integrator manifest is not readable.');
        }

        try {
            $payload = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The WordPress integrator manifest is not valid JSON.', 0, $exception);
        }
        if (!is_array($payload)) {
            throw new InvalidArgumentException('The WordPress integrator manifest root must be an object.');
        }

        $directory = dirname($path);
        self::validate($payload);
        $keyPath = self::resolvePath($directory, (string) self::value($payload, 'authority.public_key_file'));
        if (!is_file($keyPath) || !is_readable($keyPath)) {
            throw new InvalidArgumentException('The authority public key file is not readable.');
        }
        $publicKey = trim((string) file_get_contents($keyPath));
        $resource = openssl_pkey_get_public($publicKey);
        if ($resource === false) {
            throw new InvalidArgumentException('The authority public key is invalid.');
        }

        return new self($payload, $publicKey . "\n");
    }

    public function productCode(): string { return strtolower(trim((string) self::value($this->payload, 'product.code'))); }
    public function productName(): string { return trim((string) self::value($this->payload, 'product.name')); }
    public function packageIdentifier(): string { return strtolower(trim((string) self::value($this->payload, 'product.package_identifier'))); }
    public function homepage(): string { return trim((string) self::value($this->payload, 'product.homepage')); }
    public function minimumPhp(): string { return trim((string) self::value($this->payload, 'product.minimum_php')); }
    public function minimumWordPress(): string { return trim((string) self::value($this->payload, 'product.minimum_wordpress')); }
    public function testedWordPress(): string { return trim((string) self::value($this->payload, 'product.tested_wordpress')); }
    public function namespacePrefix(): string
    {
        $base = trim((string) self::value($this->payload, 'namespace'), ' \\');
        $scope = strtoupper(substr(hash('sha256', $this->productCode() . '|' . $this->packageIdentifier()), 0, 12));

        return $base . '\\ZLC' . $scope;
    }
    public function authorityId(): string { return strtolower(trim((string) self::value($this->payload, 'authority.id'))); }
    public function authorityKeyId(): string { return strtolower(trim((string) self::value($this->payload, 'authority.key_id'))); }
    public function authorityPublicKey(): string { return $this->publicKey; }
    public function allowsPrivateNetwork(): bool { return (bool) self::value($this->payload, 'authority.allow_private_network'); }

    /** @return list<string> */
    public function packageDownloadHosts(): array
    {
        $hosts = self::value($this->payload, 'authority.package_download_hosts');
        if (!is_array($hosts)) {
            throw new InvalidArgumentException('The package download host allowlist is invalid.');
        }

        return array_values(array_map(static fn (mixed $host): string => strtolower(trim((string) $host)), $hosts));
    }
    public function timeout(): int { return (int) self::value($this->payload, 'runtime.timeout'); }
    public function validationRetrySeconds(): int { return (int) self::value($this->payload, 'runtime.validation_retry_seconds'); }
    public function updateCheckSeconds(): int { return (int) self::value($this->payload, 'runtime.update_check_seconds'); }
    public function lockSeconds(): int { return (int) self::value($this->payload, 'runtime.lock_seconds'); }
    public function adminCapability(): string { return trim((string) self::value($this->payload, 'runtime.admin_capability')); }

    /** @return array<string,string> */
    public function endpoints(): array
    {
        $endpoints = [];
        foreach (self::ENDPOINTS as $operation) {
            $endpoints[$operation] = rtrim(trim((string) self::value($this->payload, 'authority.endpoints.' . $operation)), '/');
        }

        return $endpoints;
    }

    public function optionPrefix(): string
    {
        $code = str_replace('-', '_', $this->productCode());

        return 'zithis_lc_' . $code . '_' . substr(hash('sha256', $this->packageIdentifier()), 0, 12);
    }

    public function hookPrefix(): string
    {
        return $this->optionPrefix();
    }

    /** @return array<string,mixed> */
    public function publicPayload(): array
    {
        return [
            'contract_version' => 2,
            'product' => [
                'code' => $this->productCode(),
                'name' => $this->productName(),
                'package_identifier' => $this->packageIdentifier(),
                'homepage' => $this->homepage(),
                'minimum_php' => $this->minimumPhp(),
                'minimum_wordpress' => $this->minimumWordPress(),
                'tested_wordpress' => $this->testedWordPress(),
            ],
            'namespace' => $this->namespacePrefix(),
            'authority' => [
                'id' => $this->authorityId(),
                'key_id' => $this->authorityKeyId(),
                'public_key' => $this->authorityPublicKey(),
                'allow_private_network' => $this->allowsPrivateNetwork(),
                'package_download_hosts' => $this->packageDownloadHosts(),
                'endpoints' => $this->endpoints(),
            ],
            'runtime' => [
                'timeout' => $this->timeout(),
                'validation_retry_seconds' => $this->validationRetrySeconds(),
                'update_check_seconds' => $this->updateCheckSeconds(),
                'lock_seconds' => $this->lockSeconds(),
                'admin_capability' => $this->adminCapability(),
                'option_prefix' => $this->optionPrefix(),
                'hook_prefix' => $this->hookPrefix(),
            ],
        ];
    }

    /** @param array<string,mixed> $payload */
    private static function validate(array $payload): void
    {
        if ((int) ($payload['contract_version'] ?? 0) !== 2) {
            throw new InvalidArgumentException('The WordPress integrator manifest contract version must be 2.');
        }

        $code = strtolower(trim((string) self::value($payload, 'product.code')));
        if (preg_match('/^[a-z][a-z0-9-]{2,63}$/', $code) !== 1 || $code === 'zithis') {
            throw new InvalidArgumentException('The standalone WordPress product code is invalid.');
        }
        $name = trim((string) self::value($payload, 'product.name'));
        if (strlen($name) < 2 || strlen($name) > 120) {
            throw new InvalidArgumentException('The standalone WordPress product name is invalid.');
        }
        $package = strtolower(trim((string) self::value($payload, 'product.package_identifier')));
        if (preg_match('/^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*\.php$/', $package) !== 1 || $package === 'zithis/zithis.php') {
            throw new InvalidArgumentException('The standalone WordPress package identifier must use plugin-folder/main-file.php.');
        }

        $homepage = trim((string) self::value($payload, 'product.homepage'));
        if ($homepage !== '' && !self::httpUrl($homepage)) {
            throw new InvalidArgumentException('The standalone WordPress product homepage must be empty or an HTTP or HTTPS URL.');
        }
        foreach (['minimum_php', 'minimum_wordpress'] as $field) {
            $version = trim((string) self::value($payload, 'product.' . $field));
            if (preg_match('/^\d+\.\d+(?:\.\d+)?$/', $version) !== 1) {
                throw new InvalidArgumentException('The product version requirements are invalid.');
            }
        }
        $testedWordPress = trim((string) self::value($payload, 'product.tested_wordpress'));
        if ($testedWordPress !== '' && preg_match('/^\d+\.\d+(?:\.\d+)?$/', $testedWordPress) !== 1) {
            throw new InvalidArgumentException('The tested WordPress version is invalid.');
        }

        $namespace = trim((string) self::value($payload, 'namespace'));
        $segments = explode('\\', $namespace);
        $validNamespace = count($segments) >= 3;
        foreach ($segments as $segment) {
            if (preg_match('/^[A-Z_a-z\x80-\xff][A-Z_a-z0-9\x80-\xff]*$/', $segment) !== 1) {
                $validNamespace = false;
                break;
            }
        }
        if (!$validNamespace || str_starts_with(strtolower($namespace), 'zithis\\licenceclient')) {
            throw new InvalidArgumentException('The isolated namespace must contain at least three valid namespace segments.');
        }

        foreach (['authority.id', 'authority.key_id'] as $path) {
            $value = strtolower(trim((string) self::value($payload, $path)));
            if (preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $value) !== 1) {
                throw new InvalidArgumentException('The authority identity is invalid.');
            }
        }
        $keyFile = trim((string) self::value($payload, 'authority.public_key_file'));
        if ($keyFile === '' || str_contains($keyFile, "\0")) {
            throw new InvalidArgumentException('The authority public key file path is invalid.');
        }
        $allowPrivateNetwork = self::value($payload, 'authority.allow_private_network');
        if (!is_bool($allowPrivateNetwork)) {
            throw new InvalidArgumentException('The licence authority private-network policy must be boolean.');
        }
        foreach (self::ENDPOINTS as $operation) {
            if (!self::authorityUrl(
                trim((string) self::value($payload, 'authority.endpoints.' . $operation)),
                $allowPrivateNetwork
            )) {
                throw new InvalidArgumentException('Every licence authority endpoint must satisfy the configured transport policy.');
            }
        }

        $downloadHosts = self::value($payload, 'authority.package_download_hosts');
        if (!is_array($downloadHosts) || $downloadHosts === [] || count($downloadHosts) > 8) {
            throw new InvalidArgumentException('The package download host allowlist is invalid.');
        }
        $normalizedHosts = [];
        foreach ($downloadHosts as $host) {
            $host = strtolower(trim((string) $host));
            if (!self::validHost($host)) {
                throw new InvalidArgumentException('A package download host is invalid.');
            }
            $normalizedHosts[$host] = true;
        }
        if (count($normalizedHosts) !== count($downloadHosts)) {
            throw new InvalidArgumentException('The package download host allowlist contains duplicates.');
        }

        foreach ([
            'runtime.timeout' => [5, 120],
            'runtime.validation_retry_seconds' => [300, 86400],
            'runtime.update_check_seconds' => [3600, 604800],
            'runtime.lock_seconds' => [30, 900],
        ] as $path => [$minimum, $maximum]) {
            $value = self::value($payload, $path);
            if (!is_int($value) || $value < $minimum || $value > $maximum) {
                throw new InvalidArgumentException('The WordPress integrator runtime interval is invalid.');
            }
        }
        $capability = trim((string) self::value($payload, 'runtime.admin_capability'));
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/', $capability) !== 1) {
            throw new InvalidArgumentException('The WordPress administrator capability is invalid.');
        }
    }

    /** @param array<string,mixed> $payload */
    private static function value(array $payload, string $path): mixed
    {
        $value = $payload;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                throw new InvalidArgumentException('The WordPress integrator manifest is missing ' . $path . '.');
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private static function httpUrl(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && trim((string) ($parts['host'] ?? '')) !== '';
    }

    private static function authorityUrl(string $value, bool $allowPrivateNetwork): bool
    {
        $parts = parse_url($value);
        if (!is_array($parts)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return false;
        }
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return self::validHost($host)
            && ($scheme === 'https' || ($allowPrivateNetwork && $scheme === 'http'));
    }

    private static function validHost(string $host): bool
    {
        return $host !== ''
            && strlen($host) <= 253
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host) === 1;
    }

    private static function resolvePath(string $directory, string $path): string
    {
        $windowsAbsolute = strlen($path) >= 3
            && ctype_alpha($path[0])
            && $path[1] === ':'
            && in_array($path[2], ['\\', '/'], true);
        if (str_starts_with($path, '/') || $windowsAbsolute) {
            return self::absolutePath($path);
        }

        return self::absolutePath($directory . DIRECTORY_SEPARATOR . $path);
    }

    private static function absolutePath(string $path): string
    {
        $real = realpath($path);
        if ($real === false) {
            $directory = realpath(dirname($path));
            if ($directory === false) {
                throw new RuntimeException('The requested path does not exist.');
            }

            return $directory . DIRECTORY_SEPARATOR . basename($path);
        }

        return $real;
    }
}
