<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php;

use InvalidArgumentException;
use Zithis\LicenceClient\Security\PublicKeyPolicy;

final class Configuration
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data)
    {
        if ((int) ($data['contract_version'] ?? 0) !== 1) {
            throw new InvalidArgumentException('The PHP application integrator configuration is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9-]{2,63}$/', $this->productCode()) !== 1 || $this->productCode() === 'zithis') {
            throw new InvalidArgumentException('The PHP application product code is invalid.');
        }
        if ($this->productName() === '' || strlen($this->productName()) > 120) {
            throw new InvalidArgumentException('The PHP application product name is invalid.');
        }
        if ($this->packageIdentifier() === ''
            || strlen($this->packageIdentifier()) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $this->packageIdentifier()) !== 1) {
            throw new InvalidArgumentException('The PHP application package identifier is invalid.');
        }
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]*$/', $this->installedVersion()) !== 1
            || strlen($this->installedVersion()) > 64) {
            throw new InvalidArgumentException('The PHP application installed version is invalid.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._:\/-]{1,254}$/', $this->installationScope()) !== 1) {
            throw new InvalidArgumentException('The PHP application installation scope is invalid.');
        }
        if (!in_array($this->environment(), ['production', 'staging', 'development', 'local'], true)) {
            throw new InvalidArgumentException('The PHP application environment is invalid.');
        }
        $canonicalUri = $this->canonicalUri();
        if ($canonicalUri !== null && !$this->validCanonicalUri($canonicalUri)) {
            throw new InvalidArgumentException('The PHP application canonical URI is invalid.');
        }
        if (!$this->absolutePath($this->stateDirectory())) {
            throw new InvalidArgumentException('The PHP application state directory must be absolute.');
        }
        if (preg_match('/^[a-z][a-z0-9.-]{2,127}$/', $this->authorityId()) !== 1) {
            throw new InvalidArgumentException('The LicenceServer authority identifier is invalid.');
        }
        foreach ($this->authorityPublicKeys() as $keyId => $publicKey) {
            if (preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $keyId) !== 1
                || !PublicKeyPolicy::acceptsRs256($publicKey)) {
                throw new InvalidArgumentException('A pinned LicenceServer authority key is invalid.');
            }
        }
        if ($this->authorityPublicKeys() === []) {
            throw new InvalidArgumentException('At least one LicenceServer authority public key is required.');
        }
        foreach ($this->endpoints() as $endpoint) {
            if (!$this->validEndpoint($endpoint)) {
                throw new InvalidArgumentException('A PHP application licence endpoint is invalid.');
            }
        }
        $hosts = $this->packageDownloadHosts();
        if ($hosts === [] || count($hosts) > 8 || count(array_unique($hosts)) !== count($hosts)) {
            throw new InvalidArgumentException('The PHP application package host allowlist is invalid.');
        }
        foreach ($hosts as $host) {
            if (!$this->validHost($host)) {
                throw new InvalidArgumentException('A PHP application package host is invalid.');
            }
        }
        $this->assertRange($this->timeoutSeconds(), 5, 120, 'timeout');
        $this->assertRange($this->validationRetrySeconds(), 300, 86400, 'validation retry');
        $this->assertRange($this->updateCheckSeconds(), 3600, 604800, 'update check');
        $this->assertRange($this->lockWaitSeconds(), 0, 30, 'lock wait');
        $this->assertRange($this->maximumResponseBytes(), 65536, 8388608, 'response size');
        $this->assertRange($this->maximumPackageBytes(), 1048576, 2147483647, 'package size');
    }

    public function productCode(): string { return strtolower(trim((string) $this->get('product.code'))); }
    public function productName(): string { return trim((string) $this->get('product.name')); }
    public function packageIdentifier(): string { return trim((string) $this->get('product.package_identifier')); }
    public function installedVersion(): string { return trim((string) $this->get('product.installed_version')); }
    public function installationScope(): string { return strtolower(trim((string) $this->get('installation.scope'))); }
    public function environment(): string { return strtolower(trim((string) $this->get('installation.environment'))); }
    public function canonicalUri(): ?string
    {
        $value = $this->get('installation.canonical_uri', null);

        return is_scalar($value) && trim((string) $value) !== '' ? rtrim(trim((string) $value), '/') : null;
    }
    public function stateDirectory(): string { return rtrim(trim((string) $this->get('runtime.state_directory')), '/\\'); }
    public function authorityId(): string { return strtolower(trim((string) $this->get('authority.id'))); }

    /** @return array<string,string> */
    public function authorityPublicKeys(): array
    {
        $values = $this->get('authority.public_keys');
        if (!is_array($values)) {
            throw new InvalidArgumentException('The LicenceServer authority keys are invalid.');
        }
        $keys = [];
        foreach ($values as $keyId => $publicKey) {
            $keys[strtolower(trim((string) $keyId))] = trim((string) $publicKey) . "\n";
        }

        return $keys;
    }

    /** @return array<string,string> */
    public function endpoints(): array
    {
        $values = $this->get('authority.endpoints');
        if (!is_array($values)) {
            throw new InvalidArgumentException('The LicenceServer endpoints are invalid.');
        }

        return [
            'activate' => trim((string) ($values['activate'] ?? '')),
            'validate' => trim((string) ($values['validate'] ?? '')),
            'deactivate' => trim((string) ($values['deactivate'] ?? '')),
            'update_check' => trim((string) ($values['update_check'] ?? '')),
            'package_authorisation' => trim((string) ($values['package_authorisation'] ?? '')),
        ];
    }

    /** @return list<string> */
    public function packageDownloadHosts(): array
    {
        $values = $this->get('authority.package_download_hosts');
        if (!is_array($values)) {
            throw new InvalidArgumentException('The package host allowlist is invalid.');
        }

        return array_values(array_map(static fn (mixed $host): string => strtolower(trim((string) $host)), $values));
    }

    public function timeoutSeconds(): int { return (int) $this->get('runtime.timeout_seconds', 30); }
    public function validationRetrySeconds(): int { return (int) $this->get('runtime.validation_retry_seconds', 21600); }
    public function updateCheckSeconds(): int { return (int) $this->get('runtime.update_check_seconds', 43200); }
    public function lockWaitSeconds(): int { return (int) $this->get('runtime.lock_wait_seconds', 0); }
    public function maximumResponseBytes(): int { return (int) $this->get('runtime.maximum_response_bytes', 2097152); }
    public function maximumPackageBytes(): int { return (int) $this->get('runtime.maximum_package_bytes', 536870912); }

    public function stateNamespace(): string
    {
        return $this->productCode() . '-' . substr(hash('sha256', $this->productCode() . '|' . $this->packageIdentifier()), 0, 16);
    }

    private function get(string $path, mixed $default = null): mixed
    {
        $value = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                if (func_num_args() === 2) {
                    return $default;
                }
                throw new InvalidArgumentException('The PHP application integrator configuration is incomplete.');
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function validEndpoint(string $uri): bool
    {
        $parts = parse_url($uri);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        return strtolower((string) $parts['scheme']) === 'https' || $this->environment() === 'local';
    }

    private function validCanonicalUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (in_array($scheme, ['http', 'https'], true)) {
            return trim((string) ($parts['host'] ?? '')) !== '';
        }

        return $scheme === 'urn' && trim((string) ($parts['path'] ?? '')) !== '';
    }

    private function validHost(string $host): bool
    {
        return $host !== ''
            && strlen($host) <= 253
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host) === 1;
    }

    private function absolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function assertRange(int $value, int $minimum, int $maximum, string $name): void
    {
        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('The PHP application ' . $name . ' boundary is invalid.');
        }
    }
}
