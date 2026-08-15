<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator\Storage;

use RuntimeException;
use Zithis\LicenceClient\Value\UpdateMetadata;
use Zithis\StandaloneWordPressIntegrator\Configuration;

final class MetadataStore
{
    /** @var array<string,mixed>|null */
    private ?array $payload = null;

    public function __construct(private Configuration $configuration)
    {
    }

    /** @return array{last_validated_at:?string,failure_code:?string,failed_at:?string,temporary_failure:bool} */
    public function validation(): array
    {
        $value = $this->all()['validation'] ?? [];
        $value = is_array($value) ? $value : [];

        return [
            'last_validated_at' => $this->nullable($value['last_validated_at'] ?? null),
            'failure_code' => $this->nullable($value['failure_code'] ?? null),
            'failed_at' => $this->nullable($value['failed_at'] ?? null),
            'temporary_failure' => ($value['temporary_failure'] ?? false) === true,
        ];
    }

    public function markValidated(string $at): void
    {
        $payload = $this->all();
        $payload['validation'] = [
            'last_validated_at' => $at,
            'failure_code' => null,
            'failed_at' => null,
            'temporary_failure' => false,
        ];
        $this->save($payload);
    }

    public function recordValidationFailure(string $code, bool $temporary, string $at): void
    {
        $payload = $this->all();
        $validation = $this->validation();
        $validation['failure_code'] = $this->safeCode($code);
        $validation['failed_at'] = $at;
        $validation['temporary_failure'] = $temporary;
        $payload['validation'] = $validation;
        $this->save($payload);
    }

    public function offer(): ?UpdateMetadata
    {
        $value = $this->all()['update']['offer'] ?? null;
        if (!is_array($value)) {
            return null;
        }

        try {
            return new UpdateMetadata(
                (string) ($value['release_id'] ?? ''),
                (string) ($value['version'] ?? ''),
                (string) ($value['package_identifier'] ?? ''),
                (string) ($value['checksum_algorithm'] ?? ''),
                (string) ($value['checksum'] ?? ''),
                $this->nullable($value['minimum_php'] ?? null),
                $this->nullable($value['minimum_runtime'] ?? null),
                $this->nullable($value['published_at'] ?? null)
            );
        } catch (\Throwable) {
            return null;
        }
    }

    public function recordUpdateResult(?UpdateMetadata $offer, ?string $failureCode, bool $temporary, string $at): void
    {
        $payload = $this->all();
        $existing = is_array($payload['update'] ?? null) ? $payload['update'] : [];
        $update = [
            'last_checked_at' => $at,
            'failure_code' => $failureCode !== null ? $this->safeCode($failureCode) : null,
            'failed_at' => $failureCode !== null ? $at : null,
            'temporary_failure' => $failureCode !== null && $temporary,
            'offer' => $existing['offer'] ?? null,
        ];
        if ($failureCode === null) {
            $update['offer'] = $offer !== null ? $this->encodeOffer($offer) : null;
        } elseif (!$temporary) {
            $update['offer'] = null;
        }
        $payload['update'] = $update;
        $this->save($payload);
    }

    public function clearOffer(): void
    {
        $payload = $this->all();
        $update = is_array($payload['update'] ?? null) ? $payload['update'] : [];
        $update['offer'] = null;
        $payload['update'] = $update;
        $this->save($payload);
    }

    public function lastUpdateCheckTimestamp(): ?int
    {
        $value = $this->nullable($this->all()['update']['last_checked_at'] ?? null);
        if ($value === null) {
            return null;
        }
        $timestamp = strtotime($value);

        return is_int($timestamp) ? $timestamp : null;
    }

    public function clearLicenceMetadata(): void
    {
        $payload = $this->all();
        unset($payload['validation']);
        $payload['update'] = [
            'last_checked_at' => null,
            'failure_code' => null,
            'failed_at' => null,
            'temporary_failure' => false,
            'offer' => null,
        ];
        $this->save($payload);
    }

    /** @return array<string,mixed> */
    private function all(): array
    {
        if ($this->payload !== null) {
            return $this->payload;
        }
        if (!function_exists('get_option')) {
            throw new RuntimeException('The WordPress metadata store is unavailable.');
        }
        $value = get_option($this->configuration->metadataOption(), []);
        if (!is_array($value)) {
            throw new RuntimeException('The WordPress licence metadata is invalid.');
        }
        if ($value !== [] && (int) ($value['contract_version'] ?? 0) !== 1) {
            throw new RuntimeException('The WordPress licence metadata contract is invalid.');
        }

        return $this->payload = $value;
    }

    /** @param array<string,mixed> $payload */
    private function save(array $payload): void
    {
        if (!function_exists('update_option') || !function_exists('get_option')) {
            throw new RuntimeException('The WordPress metadata store is unavailable.');
        }
        $payload['contract_version'] = 1;
        $result = update_option($this->configuration->metadataOption(), $payload, false);
        if (!$result && get_option($this->configuration->metadataOption(), null) !== $payload) {
            throw new RuntimeException('The WordPress licence metadata could not be stored.');
        }
        $this->payload = $payload;
    }

    /** @return array<string,mixed> */
    private function encodeOffer(UpdateMetadata $offer): array
    {
        return [
            'release_id' => $offer->releaseId(),
            'version' => $offer->version(),
            'package_identifier' => $offer->packageIdentifier(),
            'checksum_algorithm' => $offer->checksumAlgorithm(),
            'checksum' => $offer->checksum(),
            'minimum_php' => $offer->minimumPhp(),
            'minimum_runtime' => $offer->minimumRuntime(),
            'published_at' => $offer->publishedAt(),
        ];
    }

    private function nullable(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function safeCode(string $code): string
    {
        $code = strtolower(trim($code));

        return preg_match('/^[a-z0-9._-]{2,120}$/', $code) === 1 ? $code : 'operation_failed';
    }
}
