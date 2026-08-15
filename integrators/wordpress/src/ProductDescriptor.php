<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator;

use RuntimeException;
use Zithis\LicenceClient\Contract\ProductDescriptor as ProductDescriptorContract;

final class ProductDescriptor implements ProductDescriptorContract
{
    private string $installedVersion;

    public function __construct(
        private Configuration $configuration,
        private string $pluginFile
    ) {
        $this->pluginFile = $this->realPluginFile($this->pluginFile);
        if (!function_exists('plugin_basename') || !function_exists('get_file_data')) {
            throw new RuntimeException('The WordPress plugin identity APIs are unavailable.');
        }
        $actual = strtolower((string) plugin_basename($this->pluginFile));
        if (!hash_equals($this->configuration->packageIdentifier(), $actual)) {
            throw new RuntimeException('The installed plugin package identifier does not match the generated licence integration.');
        }
        $headers = get_file_data($this->pluginFile, ['Version' => 'Version'], 'plugin');
        $version = trim((string) ($headers['Version'] ?? ''));
        if ($version === '' || strlen($version) > 64 || preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]*$/', $version) !== 1) {
            throw new RuntimeException('The installed plugin version is invalid.');
        }
        $this->installedVersion = $version;
    }

    public function code(): string { return $this->configuration->productCode(); }
    public function packageIdentifier(): string { return $this->configuration->packageIdentifier(); }
    public function installedVersion(): string { return $this->installedVersion; }
    public function pluginFile(): string { return $this->pluginFile; }

    private function realPluginFile(string $path): string
    {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new RuntimeException('The standalone WordPress plugin bootstrap file is not readable.');
        }

        return $real;
    }
}
