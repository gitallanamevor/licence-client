<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator\Update;

use RuntimeException;
use stdClass;
use Throwable;
use Zithis\LicenceClient\Value\PackageAuthorisation;
use Zithis\LicenceClient\Value\UpdateMetadata;
use Zithis\StandaloneWordPressIntegrator\Configuration;
use Zithis\StandaloneWordPressIntegrator\Http\AuthorityHttpPolicy;
use Zithis\StandaloneWordPressIntegrator\LicenceManager;
use Zithis\StandaloneWordPressIntegrator\ProductDescriptor;
use Zithis\StandaloneWordPressIntegrator\Storage\MetadataStore;

final class UpdateManager
{
    private bool $registered = false;

    public function __construct(
        private Configuration $configuration,
        private ProductDescriptor $product,
        private LicenceManager $licences,
        private MetadataStore $metadata,
        private AuthorityHttpPolicy $http
    ) {
    }

    public function register(): void
    {
        if ($this->registered || !function_exists('add_filter') || !function_exists('add_action')) {
            return;
        }

        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject'], PHP_INT_MAX - 1);
        add_filter('plugins_api', [$this, 'pluginInformation'], 20, 3);
        add_filter('upgrader_pre_download', [$this, 'download'], 20, 4);
        add_filter('upgrader_source_selection', [$this, 'verifyExtractedSource'], 20, 4);
        add_action('upgrader_process_complete', [$this, 'completed'], 20, 2);
        add_action('load-update-core.php', [$this, 'refreshForWordPressScreen'], 1);
        add_action($this->configuration->stateChangedHook(), [$this, 'licenceStateChanged'], 10, 2);
        $this->registered = true;
    }

    public function discover(bool $force = false): bool
    {
        if (!$this->licences->canReceiveUpdates()) {
            $this->metadata->clearOffer();

            return false;
        }
        $last = $this->metadata->lastUpdateCheckTimestamp();
        if (!$force && $last !== null && time() < $last + $this->configuration->updateCheckSeconds()) {
            return true;
        }

        try {
            $result = $this->licences->checkForUpdate();
            if (!$result->successful()) {
                $this->metadata->recordUpdateResult(
                    null,
                    $result->error()?->code() ?: 'invalid_response',
                    $result->error()?->retryable() ?? false,
                    gmdate(DATE_ATOM)
                );

                return false;
            }
            $offer = $result->update();
            if ($offer !== null) {
                $this->assertOffer($offer);
                if (version_compare($offer->version(), $this->product->installedVersion(), '<=')) {
                    $offer = null;
                }
            }
            $this->metadata->recordUpdateResult($offer, null, false, gmdate(DATE_ATOM));
            $this->refreshTransient();

            return true;
        } catch (Throwable) {
            $this->metadata->recordUpdateResult(null, 'local_update_check_failed', true, gmdate(DATE_ATOM));

            return false;
        }
    }

    public function inject(mixed $transient): mixed
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $packageIdentifier = $this->configuration->packageIdentifier();
        if (isset($transient->response) && is_array($transient->response)) {
            unset($transient->response[$packageIdentifier]);
        }
        if (isset($transient->no_update) && is_array($transient->no_update)) {
            unset($transient->no_update[$packageIdentifier]);
        }

        $offer = $this->metadata->offer();
        if ($offer === null
            || !$this->licences->canReceiveUpdates()
            || version_compare($offer->version(), $this->product->installedVersion(), '<=')) {
            return $transient;
        }

        try {
            $this->assertOffer($offer);
        } catch (Throwable) {
            $this->metadata->clearOffer();

            return $transient;
        }

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        $transient->response[$packageIdentifier] = $this->updateObject($offer);
        $transient->checked = is_array($transient->checked ?? null) ? $transient->checked : [];
        $transient->checked[$packageIdentifier] = $this->product->installedVersion();

        return $transient;
    }

    public function pluginInformation(mixed $result, mixed $action, mixed $args): mixed
    {
        if ($action !== 'plugin_information' || !is_object($args)) {
            return $result;
        }
        $slug = strtolower(trim((string) ($args->slug ?? '')));
        if (!hash_equals($this->slug(), $slug)) {
            return $result;
        }
        $offer = $this->metadata->offer();
        if ($offer === null) {
            return $result;
        }

        $info = new stdClass();
        $info->name = $this->configuration->productName();
        $info->slug = $this->slug();
        $info->version = $offer->version();
        if ($this->configuration->homepage() !== '') {
            $info->homepage = $this->configuration->homepage();
        }
        $info->requires = $offer->minimumRuntime() ?: $this->configuration->minimumWordPress();
        $info->requires_php = $offer->minimumPhp() ?: $this->configuration->minimumPhp();
        if ($this->configuration->testedWordPress() !== '') {
            $info->tested = $this->configuration->testedWordPress();
        }
        $info->download_link = $this->marker($offer);
        $info->sections = ['description' => $this->configuration->productName() . ' private software update.'];

        return $info;
    }

    /** @param array<string,mixed> $hookExtra */
    public function download(mixed $reply, string $package, mixed $upgrader, array $hookExtra): mixed
    {
        if ($reply !== false) {
            return $reply;
        }
        $offer = $this->metadata->offer();
        if ($offer === null || !hash_equals($this->marker($offer), $package)) {
            return $reply;
        }
        if (!$this->hookOwnsPlugin($hookExtra) || !$this->licences->canReceiveUpdates()) {
            return new \WP_Error('zithis_package_not_entitled', 'The private plugin package is not authorised.');
        }

        try {
            $this->assertOffer($offer);
            $result = $this->licences->authorizePackage($offer);
            $authorisation = $result->package();
            if (!$result->successful() || !$authorisation instanceof PackageAuthorisation) {
                throw new RuntimeException('A fresh private package authorisation could not be obtained.');
            }
            $this->assertAuthorisation($offer, $authorisation);

            return $this->downloadVerified($authorisation, $offer);
        } catch (Throwable) {
            return new \WP_Error('zithis_package_download_failed', 'The verified private plugin package could not be downloaded.');
        }
    }

    /** @param array<string,mixed> $hookExtra */
    public function verifyExtractedSource(mixed $source, string $remoteSource, mixed $upgrader, array $hookExtra): mixed
    {
        if (!is_string($source) || !$this->hookOwnsPlugin($hookExtra)) {
            return $source;
        }
        $source = rtrim($source, '/\\');
        $expectedFolder = dirname($this->configuration->packageIdentifier());
        $expectedMain = basename($this->configuration->packageIdentifier());
        if (is_link($source)
            || !hash_equals($expectedFolder, strtolower(basename($source)))
            || !is_file($source . '/' . $expectedMain)
            || is_link($source . '/' . $expectedMain)) {
            return new \WP_Error('zithis_package_structure_invalid', 'The private plugin package structure is invalid.');
        }

        return $source;
    }

    /** @param array<string,mixed> $hookExtra */
    public function completed(mixed $upgrader, array $hookExtra): void
    {
        if (($hookExtra['action'] ?? null) !== 'update' || ($hookExtra['type'] ?? null) !== 'plugin') {
            return;
        }
        $plugins = is_array($hookExtra['plugins'] ?? null)
            ? array_map('strval', $hookExtra['plugins'])
            : [(string) ($hookExtra['plugin'] ?? '')];
        if (!in_array($this->configuration->packageIdentifier(), $plugins, true)) {
            return;
        }

        $this->metadata->clearOffer();
        $this->refreshTransient();
    }

    public function refreshForWordPressScreen(): void
    {
        if (!isset($_GET['force-check'])
            || !function_exists('current_user_can')
            || !current_user_can('update_plugins')) {
            return;
        }
        $this->discover(true);
    }

    public function licenceStateChanged(string $productCode, mixed $result = null): void
    {
        if (!hash_equals($this->configuration->productCode(), strtolower(trim($productCode)))) {
            return;
        }
        if (!$this->licences->canReceiveUpdates()) {
            $this->metadata->clearOffer();
            $this->refreshTransient();
        }
    }

    private function downloadVerified(PackageAuthorisation $authorisation, UpdateMetadata $offer): string
    {
        if (!function_exists('wp_safe_remote_get')
            || !function_exists('wp_tempnam')
            || !function_exists('is_wp_error')
            || !function_exists('wp_remote_retrieve_response_code')) {
            throw new RuntimeException('The WordPress package download APIs are unavailable.');
        }
        $temporary = wp_tempnam($this->slug() . '-' . $offer->version() . '.zip');
        if (!is_string($temporary) || $temporary === '') {
            throw new RuntimeException('A temporary package file could not be created.');
        }

        try {
            $response = $this->http->packageRequest(
                $authorisation->downloadUri(),
                fn (): mixed => wp_safe_remote_get($authorisation->downloadUri(), [
                    'timeout' => 300,
                    'redirection' => 0,
                    'reject_unsafe_urls' => true,
                    'sslverify' => true,
                    'stream' => true,
                    'filename' => $temporary,
                ])
            );
            if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
                throw new RuntimeException('The private package download failed.');
            }
            $checksum = hash_file('sha256', $temporary);
            if (!is_string($checksum) || !hash_equals($offer->checksum(), strtolower($checksum))) {
                throw new RuntimeException('The private package checksum is invalid.');
            }

            return $temporary;
        } catch (Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        }
    }

    private function assertOffer(UpdateMetadata $offer): void
    {
        if (!hash_equals($this->configuration->packageIdentifier(), $offer->packageIdentifier())
            || version_compare($offer->version(), $this->product->installedVersion(), '<=')) {
            throw new RuntimeException('The private update offer does not match the installed product.');
        }
    }

    private function assertAuthorisation(UpdateMetadata $offer, PackageAuthorisation $authorisation): void
    {
        $this->http->assertPackageDownloadUri($authorisation->downloadUri());
        if (!hash_equals($offer->releaseId(), $authorisation->releaseId())
            || !hash_equals($offer->checksum(), $authorisation->checksum())
            || $authorisation->expiresAt()->getTimestamp() <= time()
            || !str_contains($authorisation->downloadUri(), rawurlencode($authorisation->token()))) {
            throw new RuntimeException('The private package authorisation is invalid.');
        }
    }

    private function updateObject(UpdateMetadata $offer): stdClass
    {
        $update = new stdClass();
        $update->id = $this->configuration->productCode();
        $update->slug = $this->slug();
        $update->plugin = $this->configuration->packageIdentifier();
        $update->new_version = $offer->version();
        if ($this->configuration->homepage() !== '') {
            $update->url = $this->configuration->homepage();
        }
        $update->package = $this->marker($offer);
        $update->requires = $offer->minimumRuntime() ?: $this->configuration->minimumWordPress();
        $update->requires_php = $offer->minimumPhp() ?: $this->configuration->minimumPhp();
        if ($this->configuration->testedWordPress() !== '') {
            $update->tested = $this->configuration->testedWordPress();
        }

        return $update;
    }

    /** @param array<string,mixed> $hookExtra */
    private function hookOwnsPlugin(array $hookExtra): bool
    {
        $plugin = strtolower(trim((string) ($hookExtra['plugin'] ?? '')));
        if ($plugin !== '') {
            return hash_equals($this->configuration->packageIdentifier(), $plugin);
        }
        $plugins = is_array($hookExtra['plugins'] ?? null) ? array_map('strtolower', array_map('strval', $hookExtra['plugins'])) : [];

        return in_array($this->configuration->packageIdentifier(), $plugins, true);
    }

    private function marker(UpdateMetadata $offer): string
    {
        return 'zithis-private://' . rawurlencode($this->configuration->productCode()) . '/' . rawurlencode($offer->releaseId());
    }

    private function slug(): string
    {
        return dirname($this->configuration->packageIdentifier());
    }

    private function refreshTransient(): void
    {
        if (!function_exists('get_site_transient') || !function_exists('set_site_transient')) {
            return;
        }
        $transient = get_site_transient('update_plugins');
        if (!is_object($transient)) {
            $transient = new stdClass();
        }
        $transient = $this->inject($transient);
        $transient->last_checked = time();
        set_site_transient('update_plugins', $transient);
    }
}
