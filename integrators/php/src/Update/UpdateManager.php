<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php\Update;

use RuntimeException;
use Throwable;
use Zithis\LicenceClient\Contract\Clock;
use Zithis\LicenceClient\Integrator\Php\Configuration;
use Zithis\LicenceClient\Integrator\Php\LicenceManager;
use Zithis\LicenceClient\Integrator\Php\State\MetadataStore;
use Zithis\LicenceClient\Value\PackageAuthorisation;
use Zithis\LicenceClient\Value\UpdateMetadata;

final class UpdateManager
{
    public function __construct(
        private Configuration $configuration,
        private LicenceManager $licences,
        private MetadataStore $metadata,
        private Clock $clock,
        private PackageTransport $transport
    ) {
    }

    public function discover(bool $force = false): bool
    {
        if (!$this->licences->canReceiveUpdates()) {
            $this->metadata->clearOffer();

            return false;
        }
        $last = $this->metadata->lastUpdateCheckTimestamp();
        if (!$force && $last !== null && $this->clock->now()->getTimestamp() < $last + $this->configuration->updateCheckSeconds()) {
            return true;
        }
        try {
            $result = $this->licences->checkForUpdate();
            if (!$result->successful()) {
                $this->metadata->recordUpdateResult(
                    null,
                    $result->error()?->code() ?: 'invalid_response',
                    $result->error()?->retryable() ?? false,
                    $this->clock->now()->format(DATE_ATOM)
                );

                return false;
            }
            $offer = $result->update();
            if ($offer !== null) {
                $this->assertOffer($offer);
                if (version_compare($offer->version(), $this->configuration->installedVersion(), '<=')) {
                    $offer = null;
                }
            }
            $this->metadata->recordUpdateResult($offer, null, false, $this->clock->now()->format(DATE_ATOM));

            return true;
        } catch (Throwable) {
            $this->metadata->recordUpdateResult(
                null,
                'local_update_check_failed',
                true,
                $this->clock->now()->format(DATE_ATOM)
            );

            return false;
        }
    }

    public function offer(): ?UpdateMetadata
    {
        $offer = $this->metadata->offer();
        if ($offer === null || version_compare($offer->version(), $this->configuration->installedVersion(), '<=')) {
            return null;
        }
        try {
            $this->assertOffer($offer);

            return $offer;
        } catch (Throwable) {
            $this->metadata->clearOffer();

            return null;
        }
    }

    public function stage(UpdateMetadata $offer, string $destinationDirectory): StagedPackage
    {
        if (!$this->licences->canReceiveUpdates()) {
            throw new RuntimeException('The application licence does not permit private updates.');
        }
        $this->assertOffer($offer);
        $result = $this->licences->authorizePackage($offer);
        $authorisation = $result->package();
        if (!$result->successful() || !$authorisation instanceof PackageAuthorisation) {
            throw new RuntimeException('A fresh private package authorisation could not be obtained.');
        }
        $this->assertAuthorisation($offer, $authorisation);
        $directory = rtrim(trim($destinationDirectory), '/\\');
        if ($directory === '' || is_link($directory)) {
            throw new RuntimeException('The application package staging directory is invalid.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('The application package staging directory could not be created.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('The application package staging directory is not writable.');
        }
        $temporary = $directory . DIRECTORY_SEPARATOR . '.' . $this->configuration->productCode()
            . '-' . bin2hex(random_bytes(8)) . '.part';
        $this->transport->download(
            $authorisation->downloadUri(),
            $temporary,
            max(120, $this->configuration->timeoutSeconds()),
            $this->configuration->maximumPackageBytes()
        );
        try {
            $checksum = hash_file('sha256', $temporary);
            if (!is_string($checksum) || !hash_equals($offer->checksum(), strtolower($checksum))) {
                throw new RuntimeException('The private application package checksum is invalid.');
            }
            $name = $this->configuration->productCode() . '-' . $offer->version() . '-'
                . substr(hash('sha256', $offer->releaseId()), 0, 12) . '.zip';
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_link($path) || (file_exists($path) && !is_file($path))) {
                throw new RuntimeException('The staged application package path is invalid.');
            }
            if (file_exists($path) && !unlink($path)) {
                throw new RuntimeException('The previous staged application package could not be replaced.');
            }
            if (!rename($temporary, $path)) {
                throw new RuntimeException('The application package could not be staged atomically.');
            }
            @chmod($path, 0600);

            return new StagedPackage($path, $offer->releaseId(), $offer->version(), strtolower($checksum));
        } catch (Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        }
    }

    private function assertOffer(UpdateMetadata $offer): void
    {
        if (!hash_equals($this->configuration->packageIdentifier(), $offer->packageIdentifier())
            || version_compare($offer->version(), $this->configuration->installedVersion(), '<=')) {
            throw new RuntimeException('The private update offer does not match the installed application.');
        }
    }

    private function assertAuthorisation(UpdateMetadata $offer, PackageAuthorisation $authorisation): void
    {
        $parts = parse_url($authorisation->downloadUri());
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''))) : '';
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !in_array($host, $this->configuration->packageDownloadHosts(), true)
            || !hash_equals($offer->releaseId(), $authorisation->releaseId())
            || !hash_equals($offer->checksum(), $authorisation->checksum())
            || $authorisation->expiresAt() <= $this->clock->now()
            || !str_contains($authorisation->downloadUri(), rawurlencode($authorisation->token()))) {
            throw new RuntimeException('The private package authorisation is invalid.');
        }
    }
}
