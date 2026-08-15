<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator;

use InvalidArgumentException;
use Zithis\LicenceClient\Security\PublicKeyPolicy;

final class Configuration
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data)
    {
        if ((int) ($data['contract_version'] ?? 0) !== 2) {
            throw new InvalidArgumentException('The standalone WordPress integrator configuration is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9-]{2,63}$/', $this->productCode()) !== 1 || $this->productCode() === 'zithis') {
            throw new InvalidArgumentException('The standalone WordPress product code is invalid.');
        }
        if (strlen($this->productName()) < 2 || strlen($this->productName()) > 120) {
            throw new InvalidArgumentException('The standalone WordPress product name is invalid.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*\.php$/', $this->packageIdentifier()) !== 1
            || $this->packageIdentifier() === 'zithis/zithis.php') {
            throw new InvalidArgumentException('The standalone WordPress package identifier is invalid.');
        }
        if ($this->homepage() !== '' && !$this->httpUrl($this->homepage())) {
            throw new InvalidArgumentException('The standalone WordPress product homepage is invalid.');
        }
        foreach ([$this->minimumPhp(), $this->minimumWordPress()] as $version) {
            if (preg_match('/^\d+\.\d+(?:\.\d+)?$/', $version) !== 1) {
                throw new InvalidArgumentException('A standalone WordPress product version boundary is invalid.');
            }
        }
        if ($this->testedWordPress() !== '' && preg_match('/^\d+\.\d+(?:\.\d+)?$/', $this->testedWordPress()) !== 1) {
            throw new InvalidArgumentException('The standalone WordPress tested version is invalid.');
        }
        foreach ([$this->authorityId(), $this->authorityKeyId()] as $identity) {
            if (preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $identity) !== 1) {
                throw new InvalidArgumentException('The standalone WordPress authority identity is invalid.');
            }
        }
        if (!PublicKeyPolicy::acceptsRs256($this->authorityPublicKey())) {
            throw new InvalidArgumentException('The standalone WordPress authority public key is invalid.');
        }
        if (!is_bool($this->get('authority.allow_private_network'))) {
            throw new InvalidArgumentException('The standalone WordPress authority transport policy is invalid.');
        }
        foreach ($this->endpoints() as $endpoint) {
            if (!$this->authorityUrl($endpoint)) {
                throw new InvalidArgumentException('A standalone WordPress licence endpoint is invalid.');
            }
        }
        $hosts = $this->packageDownloadHosts();
        if (count($hosts) > 8 || count(array_unique($hosts)) !== count($hosts)) {
            throw new InvalidArgumentException('The package download host allowlist is invalid.');
        }
        foreach ($hosts as $host) {
            if (!$this->validHost($host)) {
                throw new InvalidArgumentException('A package download host is invalid.');
            }
        }
        $this->assertRange($this->timeout(), 5, 120);
        $this->assertRange($this->validationRetrySeconds(), 300, 86400);
        $this->assertRange($this->updateCheckSeconds(), 3600, 604800);
        $this->assertRange($this->lockSeconds(), 30, 900);
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/', $this->adminCapability()) !== 1) {
            throw new InvalidArgumentException('The standalone WordPress administrator capability is invalid.');
        }
        foreach ([$this->optionPrefix(), $this->hookPrefix()] as $prefix) {
            if (preg_match('/^[a-z][a-z0-9_]{4,120}$/', $prefix) !== 1) {
                throw new InvalidArgumentException('The standalone WordPress integration prefix is invalid.');
            }
        }
    }

    public function productCode(): string { return strtolower(trim((string) $this->get('product.code'))); }
    public function productName(): string { return trim((string) $this->get('product.name')); }
    public function packageIdentifier(): string { return strtolower(trim((string) $this->get('product.package_identifier'))); }
    public function homepage(): string { return trim((string) $this->get('product.homepage')); }
    public function minimumPhp(): string { return trim((string) $this->get('product.minimum_php')); }
    public function minimumWordPress(): string { return trim((string) $this->get('product.minimum_wordpress')); }
    public function testedWordPress(): string { return trim((string) $this->get('product.tested_wordpress')); }
    public function authorityId(): string { return strtolower(trim((string) $this->get('authority.id'))); }
    public function authorityKeyId(): string { return strtolower(trim((string) $this->get('authority.key_id'))); }
    public function authorityPublicKey(): string { return trim((string) $this->get('authority.public_key')) . "\n"; }
    public function allowsPrivateNetwork(): bool { return (bool) $this->get('authority.allow_private_network'); }

    /** @return list<string> */
    public function packageDownloadHosts(): array
    {
        $hosts = $this->get('authority.package_download_hosts');
        if (!is_array($hosts) || $hosts === []) {
            throw new InvalidArgumentException('The package download host allowlist is invalid.');
        }

        return array_values(array_map(static fn (mixed $host): string => strtolower(trim((string) $host)), $hosts));
    }

    public function timeout(): int { return (int) $this->get('runtime.timeout'); }
    public function validationRetrySeconds(): int { return (int) $this->get('runtime.validation_retry_seconds'); }
    public function updateCheckSeconds(): int { return (int) $this->get('runtime.update_check_seconds'); }
    public function lockSeconds(): int { return (int) $this->get('runtime.lock_seconds'); }
    public function adminCapability(): string { return trim((string) $this->get('runtime.admin_capability')); }
    public function optionPrefix(): string { return trim((string) $this->get('runtime.option_prefix')); }
    public function hookPrefix(): string { return trim((string) $this->get('runtime.hook_prefix')); }

    /** @return array<string,string> */
    public function endpoints(): array
    {
        $values = $this->get('authority.endpoints');
        if (!is_array($values)) {
            throw new InvalidArgumentException('The standalone WordPress endpoint configuration is invalid.');
        }

        return [
            'activate' => trim((string) ($values['activate'] ?? '')),
            'validate' => trim((string) ($values['validate'] ?? '')),
            'deactivate' => trim((string) ($values['deactivate'] ?? '')),
            'update_check' => trim((string) ($values['update_check'] ?? '')),
            'package_authorisation' => trim((string) ($values['package_authorisation'] ?? '')),
        ];
    }

    public function cronHook(): string { return $this->hookPrefix() . '_scheduled'; }
    public function lockOption(): string { return $this->optionPrefix() . '_lock'; }
    public function stateOption(): string { return $this->optionPrefix() . '_state'; }
    public function metadataOption(): string { return $this->optionPrefix() . '_meta'; }
    public function installationOption(): string { return $this->optionPrefix() . '_installation'; }
    public function adminSlug(): string { return $this->optionPrefix() . '_licence'; }
    public function action(string $name): string { return $this->hookPrefix() . '_' . strtolower(trim($name)); }
    public function stateChangedHook(): string { return $this->hookPrefix() . '_licence_state_changed'; }

    private function get(string $path): mixed
    {
        $value = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                throw new InvalidArgumentException('The standalone WordPress integrator configuration is incomplete.');
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function httpUrl(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && trim((string) ($parts['host'] ?? '')) !== '';
    }

    private function authorityUrl(string $value): bool
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

        return $this->validHost($host)
            && ($scheme === 'https' || ($this->allowsPrivateNetwork() && $scheme === 'http'));
    }

    private function validHost(string $host): bool
    {
        return $host !== ''
            && strlen($host) <= 253
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host) === 1;
    }

    private function assertRange(int $value, int $minimum, int $maximum): void
    {
        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('A standalone WordPress runtime interval is invalid.');
        }
    }
}
