<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Tests\Support;

use DateTimeImmutable;
use RuntimeException;
use Zithis\LicenceClient\Contract\Clock;
use Zithis\LicenceClient\Contract\CredentialStore;
use Zithis\LicenceClient\Contract\InstallationIdentity;
use Zithis\LicenceClient\Contract\Logger;
use Zithis\LicenceClient\Contract\ProductDescriptor;
use Zithis\LicenceClient\Contract\Transport;
use Zithis\LicenceClient\Enum\LogLevel;
use Zithis\LicenceClient\Enum\Operation;
use Zithis\LicenceClient\Exception\TransportFailure;
use Zithis\LicenceClient\Protocol\Base64Url;
use Zithis\LicenceClient\Protocol\Json;
use Zithis\LicenceClient\Value\Authority;
use Zithis\LicenceClient\Value\Installation;
use Zithis\LicenceClient\Value\StoredState;
use Zithis\LicenceClient\Value\TransportRequest;
use Zithis\LicenceClient\Value\TransportResponse;

final class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
}

final class ArrayCredentialStore implements CredentialStore
{
    /** @var array<string,StoredState> */
    private array $states = [];
    public function load(string $productCode): ?StoredState { return $this->states[$productCode] ?? null; }
    public function save(string $productCode, StoredState $state): void { $this->states[$productCode] = $state; }
    public function clear(string $productCode): void { unset($this->states[$productCode]); }
}

final class StaticInstallationIdentity implements InstallationIdentity
{
    public function __construct(private Installation $installation) {}
    public function installation(): Installation { return $this->installation; }
}

final class StaticProductDescriptor implements ProductDescriptor
{
    public function __construct(private string $code, private string $packageIdentifier, private string $version) {}
    public function code(): string { return $this->code; }
    public function packageIdentifier(): string { return $this->packageIdentifier; }
    public function installedVersion(): string { return $this->version; }
}

final class MemoryLogger implements Logger
{
    /** @var list<array{level:string,message:string,context:array<string,mixed>}> */
    public array $records = [];
    public function log(LogLevel $level, string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level->value, 'message' => $message, 'context' => $context];
    }
}

final class ThrowingTransport implements Transport
{
    public function send(TransportRequest $request): TransportResponse
    {
        throw new RuntimeException('timeout');
    }
}

final class TimeoutTransport implements Transport
{
    public function send(TransportRequest $request): TransportResponse
    {
        throw new TransportFailure('transport_timeout');
    }
}

final class DeactivationResponseLossTransport implements Transport
{
    /** @var list<string> */
    public array $operations = [];

    public function __construct(
        private Transport $delegate,
        private bool $remoteDeactivated,
        private string $confirmationCode = 'invalid_activation',
        private string $confirmationCategory = 'credential'
    ) {
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $this->operations[] = $request->operation()->value;

        if ($request->operation() === Operation::Deactivate) {
            throw new RuntimeException('The deactivation response was lost.');
        }

        if ($request->operation() === Operation::Validate && $this->remoteDeactivated) {
            return new TransportResponse(
                $this->confirmationCode === 'invalid_activation' ? 401 : 409,
                ['Content-Type' => 'application/json'],
                Json::encode([
                    'protocol_version' => '1.0',
                    'error' => [
                        'code' => $this->confirmationCode,
                        'category' => $this->confirmationCategory,
                        'retryable' => false,
                        'request_id' => $request->requestId(),
                    ],
                ])
            );
        }

        return $this->delegate->send($request);
    }
}

final class FixtureResponder implements Transport
{
    private function __construct(private string $privateKey, private Authority $authority, private string $packageChecksum) {}

    /** @return array{0:self,1:Authority} */
    public static function create(?string $packageChecksum = null): array
    {
        $privateKey = TestSigningKey::privateKeyPem();
        $publicKey = TestSigningKey::publicKeyPem();
        $authority = new Authority('licensing.zithis.example', ['runtime-fixture' => $publicKey]);

        return [new self($privateKey, $authority, $packageChecksum ?? str_repeat('a', 64)), $authority];
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $requestPayload = Json::decode($request->body());
        $operation = $request->operation()->value;
        $productCode = (string) ($requestPayload['product']['code'] ?? '');
        $packageIdentifier = (string) ($requestPayload['product']['package_identifier'] ?? '');
        $releaseId = 'release-' . $productCode . '-1.1.28';
        $requestTime = new DateTimeImmutable((string) ($requestPayload['protocol']['timestamp'] ?? '2026-08-02T14:00:00+00:00'));
        $issuedAt = $requestTime->modify('-1 minute')->format(DATE_ATOM);
        $responseExpiresAt = $requestTime->modify('+9 minutes')->format(DATE_ATOM);
        $packageExpiresAt = $requestTime->modify('+10 minutes')->format(DATE_ATOM);
        $licence = [
            'id' => 'licence-runtime-0001',
            'status' => $operation === 'deactivate' ? 'deactivated' : 'active',
            'entitlements' => ['suite', 'updates'],
            'term_started_at' => $requestTime->modify('-30 days')->format(DATE_ATOM),
            'expires_at' => $requestTime->modify('+365 days')->format(DATE_ATOM),
            'validation_due_at' => $requestTime->modify('+1 day')->format(DATE_ATOM),
            'grace_expires_at' => $requestTime->modify('+15 days')->format(DATE_ATOM),
            'activation_limits' => ['production' => 1, 'non_production' => 1],
            'activation_usage' => ['production' => $operation === 'deactivate' ? 0 : 1, 'non_production' => 0],
        ];
        $result = match ($operation) {
            'activate' => [
                'credential' => [
                    'activation_id' => '33333333-3333-4333-8333-333333333333',
                    'activation_secret' => str_repeat('S', 43),
                ],
                'licence' => $licence,
            ],
            'validate', 'deactivate' => ['licence' => $licence],
            'update_check' => [
                'licence' => $licence,
                'update' => [
                    'release_id' => $releaseId,
                    'version' => '1.1.28',
                    'package_identifier' => $packageIdentifier,
                    'checksum_algorithm' => 'sha256',
                    'checksum' => $this->packageChecksum,
                    'minimum_php' => '8.1',
                    'minimum_runtime' => 'wordpress-6.5',
                    'published_at' => $requestTime->modify('-1 hour')->format(DATE_ATOM),
                ],
            ],
            'package_authorisation' => [
                'package' => [
                    'release_id' => $releaseId,
                    'package_identifier' => $packageIdentifier,
                    'download_uri' => 'https://licensing.zithis.example/v1/packages/download?token=' . str_repeat('P', 64),
                    'package_token' => str_repeat('P', 64),
                    'expires_at' => $packageExpiresAt,
                    'checksum' => $this->packageChecksum,
                ],
            ],
            default => throw new RuntimeException('Unsupported fixture operation.'),
        };

        $signed = [
            'protocol_version' => '1.0',
            'request_id' => $request->requestId(),
            'request_nonce' => $request->nonce(),
            'issued_at' => $issuedAt,
            'expires_at' => $responseExpiresAt,
            'operation' => $operation,
            'product' => [
                'code' => (string) ($requestPayload['product']['code'] ?? ''),
                'package_identifier' => (string) ($requestPayload['product']['package_identifier'] ?? ''),
            ],
            'installation' => [
                'id' => (string) ($requestPayload['installation']['id'] ?? ''),
                'scope' => (string) ($requestPayload['installation']['scope'] ?? ''),
                'environment' => (string) ($requestPayload['installation']['environment'] ?? ''),
            ],
            'result' => $result,
        ];
        $payload = Json::encode($signed);
        $signature = '';
        if (!openssl_sign($payload, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Fixture response could not be signed.');
        }
        $body = Json::encode([
            'protocol_version' => '1.0',
            'signed_payload' => Base64Url::encode($payload),
            'signature' => [
                'authority_id' => $this->authority->id(),
                'key_id' => 'runtime-fixture',
                'algorithm' => 'RS256',
                'value' => Base64Url::encode($signature),
            ],
        ]);

        return new TransportResponse(200, ['Content-Type' => 'application/json'], $body);
    }
}
