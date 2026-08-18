<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php\State;

use DateTimeImmutable;
use RuntimeException;
use Throwable;
use Zithis\LicenceClient\Contract\Clock;
use Zithis\LicenceClient\Contract\CredentialStore;
use Zithis\LicenceClient\Contract\ValidationContactStore;
use Zithis\LicenceClient\Integrator\Php\Configuration;
use Zithis\LicenceClient\Protocol\Base64Url;
use Zithis\LicenceClient\Runtime\LicenceStateCodec;
use Zithis\LicenceClient\Value\ActivationCredential;
use Zithis\LicenceClient\Value\StoredState;

final class EncryptedCredentialStore implements CredentialStore, ValidationContactStore
{
    private const CONTRACT_VERSION = 1;
    private const CIPHER = 'aes-256-gcm';

    private bool $resolved = false;
    private ?StoredState $state = null;

    public function __construct(
        private Configuration $configuration,
        private StateDirectory $directory,
        private AtomicFile $files,
        private SecretKeyStore $keys,
        private MetadataStore $metadata,
        private Clock $clock,
        private LicenceStateCodec $codec
    ) {
    }

    public function configured(): bool
    {
        return $this->files->read($this->directory->file('credential.bin'), 4194304) !== null;
    }

    public function load(string $productCode): ?StoredState
    {
        $this->assertProduct($productCode);
        if ($this->resolved) {
            return $this->state;
        }
        $sealed = $this->files->read($this->directory->file('credential.bin'), 4194304);
        if ($sealed === null) {
            $this->resolved = true;

            return null;
        }
        try {
            $payload = $this->decrypt(trim($sealed));
            if ((int) ($payload['contract_version'] ?? 0) !== self::CONTRACT_VERSION) {
                throw new RuntimeException('The stored licence contract is invalid.');
            }
            $credential = is_array($payload['credential'] ?? null) ? $payload['credential'] : [];
            $licence = is_array($payload['licence'] ?? null) ? $payload['licence'] : [];
            $this->state = new StoredState(
                new ActivationCredential(
                    (string) ($credential['activation_id'] ?? ''),
                    (string) ($credential['activation_secret'] ?? '')
                ),
                $this->codec->decode($licence)
            );
            $this->resolved = true;

            return $this->state;
        } catch (Throwable $exception) {
            throw new RuntimeException('The stored licence state could not be read.', 0, $exception);
        }
    }

    public function save(string $productCode, StoredState $state): void
    {
        $this->assertProduct($productCode);
        $payload = [
            'contract_version' => self::CONTRACT_VERSION,
            'credential' => $state->credential()->toArray(),
            'licence' => $this->codec->encode($state->licence()),
        ];
        $this->files->write($this->directory->file('credential.bin'), $this->encrypt($payload));
        $this->state = $state;
        $this->resolved = true;
    }

    public function clear(string $productCode): void
    {
        $this->assertProduct($productCode);
        $this->files->delete($this->directory->file('credential.bin'));
        $this->metadata->clearLicenceMetadata();
        $this->state = null;
        $this->resolved = true;
    }

    public function markValidated(string $productCode, DateTimeImmutable $validatedAt): void
    {
        $this->assertProduct($productCode);
        if (!$this->configured()) {
            return;
        }
        $this->metadata->markValidated($validatedAt->format(DATE_ATOM));
    }

    public function recordValidationFailure(string $code, bool $temporary): void
    {
        if (!$this->configured()) {
            return;
        }
        $this->metadata->recordValidationFailure(
            $code,
            $temporary,
            $this->clock->now()->format(DATE_ATOM)
        );
    }

    /** @return array{last_validated_at:?string,failure_code:?string,failed_at:?string,temporary_failure:bool} */
    public function validation(): array
    {
        return $this->metadata->validation();
    }

    /** @param array<string,mixed> $payload */
    private function encrypt(array $payload): string
    {
        try {
            $plain = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $nonce = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt(
                $plain,
                self::CIPHER,
                $this->keys->key(),
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                $this->aad(),
                16
            );
            if (!is_string($ciphertext) || strlen($tag) !== 16) {
                throw new RuntimeException('The licence state could not be encrypted.');
            }

            return Base64Url::encode(json_encode([
                'contract_version' => self::CONTRACT_VERSION,
                'cipher' => self::CIPHER,
                'nonce' => Base64Url::encode($nonce),
                'tag' => Base64Url::encode($tag),
                'ciphertext' => Base64Url::encode($ciphertext),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            throw new RuntimeException('The licence state could not be encrypted.', 0, $exception);
        }
    }

    /** @return array<string,mixed> */
    private function decrypt(string $sealed): array
    {
        $envelope = json_decode(Base64Url::decode($sealed), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($envelope)
            || (int) ($envelope['contract_version'] ?? 0) !== self::CONTRACT_VERSION
            || (string) ($envelope['cipher'] ?? '') !== self::CIPHER) {
            throw new RuntimeException('The licence state envelope is invalid.');
        }
        $nonce = Base64Url::decode((string) ($envelope['nonce'] ?? ''));
        $tag = Base64Url::decode((string) ($envelope['tag'] ?? ''));
        $ciphertext = Base64Url::decode((string) ($envelope['ciphertext'] ?? ''));
        if (strlen($nonce) !== 12 || strlen($tag) !== 16 || $ciphertext === '') {
            throw new RuntimeException('The licence state envelope is invalid.');
        }
        $plain = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->keys->key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->aad()
        );
        if (!is_string($plain)) {
            throw new RuntimeException('The licence state authentication failed.');
        }
        $payload = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('The licence state payload is invalid.');
        }

        return $payload;
    }

    private function aad(): string
    {
        return 'zithis-php-application-licence|1|'
            . $this->configuration->productCode() . '|'
            . $this->configuration->packageIdentifier();
    }

    private function assertProduct(string $productCode): void
    {
        if (!hash_equals($this->configuration->productCode(), strtolower(trim($productCode)))) {
            throw new RuntimeException('The credential store product identity does not match this application.');
        }
    }
}
