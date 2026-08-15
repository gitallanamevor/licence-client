<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Protocol;

use DateTimeImmutable;
use Throwable;
use Zithis\LicenceClient\ClientPackage;
use Zithis\LicenceClient\Enum\ErrorCategory;
use Zithis\LicenceClient\Enum\LicenceStatus;
use Zithis\LicenceClient\Enum\Operation;
use Zithis\LicenceClient\Security\SignatureVerifier;
use Zithis\LicenceClient\State\StateInterpreter;
use Zithis\LicenceClient\Value\ActivationCredential;
use Zithis\LicenceClient\Value\Authority;
use Zithis\LicenceClient\Value\ClientError;
use Zithis\LicenceClient\Value\Installation;
use Zithis\LicenceClient\Value\LicenceState;
use Zithis\LicenceClient\Value\OperationResult;
use Zithis\LicenceClient\Value\PackageAuthorisation;
use Zithis\LicenceClient\Value\Product;
use Zithis\LicenceClient\Value\TransportRequest;
use Zithis\LicenceClient\Value\TransportResponse;
use Zithis\LicenceClient\Value\UpdateMetadata;

final class ResponseDecoder
{
    public function __construct(
        private SignatureVerifier $signatures,
        private StateInterpreter $states,
        private int $clockSkewSeconds = 300
    ) {
        $this->clockSkewSeconds = max(0, min($this->clockSkewSeconds, 900));
    }

    public function decode(
        TransportRequest $request,
        TransportResponse $response,
        Product $product,
        Installation $installation,
        Authority $authority,
        DateTimeImmutable $now
    ): OperationResult {
        if ($response->status() < 200 || $response->status() >= 300) {
            return OperationResult::failure(
                $request->operation(),
                $request->requestId(),
                $this->decodeError($response, $request->requestId())
            );
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        if (!str_contains($contentType, 'application/json')) {
            return $this->failure($request, ErrorCategory::Protocol, 'invalid_content_type', true);
        }

        try {
            $envelope = Json::decode($response->body());
        } catch (Throwable) {
            return $this->failure($request, ErrorCategory::Protocol, 'malformed_response', true);
        }

        if (isset($envelope['error'])) {
            return OperationResult::failure(
                $request->operation(),
                $request->requestId(),
                $this->errorFromEnvelope($envelope, $request->requestId())
            );
        }

        $protocolVersion = trim((string) ($envelope['protocol_version'] ?? ''));
        if (!in_array($protocolVersion, ClientPackage::supportedProtocolVersions(), true)) {
            return $this->failure($request, ErrorCategory::Compatibility, 'unsupported_protocol_version');
        }

        $encodedPayload = trim((string) ($envelope['signed_payload'] ?? ''));
        $signature = $envelope['signature'] ?? null;
        if (!is_array($signature)) {
            return $this->failure($request, ErrorCategory::Protocol, 'missing_signature');
        }

        $authorityId = strtolower(trim((string) ($signature['authority_id'] ?? '')));
        $keyId = strtolower(trim((string) ($signature['key_id'] ?? '')));
        $algorithm = strtoupper(trim((string) ($signature['algorithm'] ?? '')));
        $value = trim((string) ($signature['value'] ?? ''));
        if (!hash_equals($authority->id(), $authorityId) || $algorithm !== 'RS256') {
            return $this->failure($request, ErrorCategory::Authority, 'untrusted_authority');
        }

        $publicKey = $authority->publicKey($keyId);
        if ($publicKey === null) {
            return $this->failure($request, ErrorCategory::Authority, 'unknown_authority_key');
        }

        try {
            $payloadBytes = Base64Url::decode($encodedPayload);
        } catch (Throwable) {
            return $this->failure($request, ErrorCategory::Protocol, 'invalid_signed_payload');
        }
        if (!$this->signatures->verify($payloadBytes, $value, $publicKey)) {
            return $this->failure($request, ErrorCategory::Authority, 'invalid_signature');
        }

        try {
            $payload = Json::decode($payloadBytes);
            $this->assertBinding($payload, $request, $product, $installation, $now, $protocolVersion);

            return $this->decodeOperationResult($request, $payload, $product, $now);
        } catch (ProtocolFailure $failure) {
            return $this->failure($request, $failure->category(), $failure->errorCode(), $failure->retryable());
        } catch (Throwable) {
            return $this->failure($request, ErrorCategory::Protocol, 'invalid_response', true);
        }
    }

    /** @param array<string,mixed> $payload */
    private function assertBinding(
        array $payload,
        TransportRequest $request,
        Product $product,
        Installation $installation,
        DateTimeImmutable $now,
        string $outerProtocolVersion
    ): void {
        if (!hash_equals($outerProtocolVersion, trim((string) ($payload['protocol_version'] ?? '')))) {
            throw ProtocolFailure::protocol('protocol_version_mismatch');
        }
        if (!hash_equals($request->requestId(), strtolower(trim((string) ($payload['request_id'] ?? ''))))) {
            throw ProtocolFailure::protocol('request_id_mismatch');
        }
        if (!hash_equals($request->nonce(), trim((string) ($payload['request_nonce'] ?? '')))) {
            throw ProtocolFailure::protocol('request_nonce_mismatch');
        }
        if (!hash_equals($request->operation()->value, trim((string) ($payload['operation'] ?? '')))) {
            throw ProtocolFailure::protocol('operation_mismatch');
        }

        $payloadProduct = $this->object($payload, 'product');
        if (
            !hash_equals($product->code(), strtolower(trim((string) ($payloadProduct['code'] ?? ''))))
            || !hash_equals($product->packageIdentifier(), trim((string) ($payloadProduct['package_identifier'] ?? '')))
        ) {
            throw ProtocolFailure::authority('product_mismatch');
        }

        $payloadInstallation = $this->object($payload, 'installation');
        if (
            !hash_equals($installation->id(), strtolower(trim((string) ($payloadInstallation['id'] ?? ''))))
            || !hash_equals($installation->scope(), strtolower(trim((string) ($payloadInstallation['scope'] ?? ''))))
            || !hash_equals($installation->environment(), strtolower(trim((string) ($payloadInstallation['environment'] ?? ''))))
        ) {
            throw ProtocolFailure::authority('installation_mismatch');
        }

        $issuedAt = $this->date((string) ($payload['issued_at'] ?? ''));
        $expiresAt = $this->date((string) ($payload['expires_at'] ?? ''));
        if ($expiresAt <= $issuedAt) {
            throw ProtocolFailure::protocol('invalid_response_window');
        }
        if ($issuedAt->getTimestamp() > $now->getTimestamp() + $this->clockSkewSeconds) {
            throw ProtocolFailure::protocol('response_from_future', true);
        }
        if ($expiresAt->getTimestamp() < $now->getTimestamp() - $this->clockSkewSeconds) {
            throw ProtocolFailure::authority('response_expired');
        }
    }

    /** @param array<string,mixed> $payload */
    private function decodeOperationResult(
        TransportRequest $request,
        array $payload,
        Product $product,
        DateTimeImmutable $now
    ): OperationResult {
        $result = $this->object($payload, 'result');
        $licence = isset($result['licence'])
            ? $this->decodeLicence($this->object($result, 'licence'), $now)
            : null;

        return match ($request->operation()) {
            Operation::Activate => OperationResult::success(
                Operation::Activate,
                $request->requestId(),
                $licence ?? throw ProtocolFailure::protocol('missing_licence'),
                $this->decodeCredential($this->object($result, 'credential'))
            ),
            Operation::Validate => OperationResult::success(
                Operation::Validate,
                $request->requestId(),
                $licence ?? throw ProtocolFailure::protocol('missing_licence')
            ),
            Operation::Deactivate => OperationResult::success(
                Operation::Deactivate,
                $request->requestId(),
                $licence ?? throw ProtocolFailure::protocol('missing_licence')
            ),
            Operation::UpdateCheck => OperationResult::success(
                Operation::UpdateCheck,
                $request->requestId(),
                $licence ?? throw ProtocolFailure::protocol('missing_licence'),
                null,
                $this->decodeUpdate($result['update'] ?? null, $product)
            ),
            Operation::PackageAuthorisation => OperationResult::success(
                Operation::PackageAuthorisation,
                $request->requestId(),
                null,
                null,
                null,
                $this->decodePackage($this->object($result, 'package'), $product, $request, $now)
            ),
        };
    }

    /** @param array<string,mixed> $payload */
    private function decodeLicence(array $payload, DateTimeImmutable $now): LicenceState
    {
        $status = LicenceStatus::tryFrom(strtolower(trim((string) ($payload['status'] ?? ''))));
        if (!$status instanceof LicenceStatus) {
            throw ProtocolFailure::protocol('invalid_licence_status');
        }
        $termStartedAt = $this->date((string) ($payload['term_started_at'] ?? ''));
        $expiresAt = $this->date((string) ($payload['expires_at'] ?? ''));
        $validationDueAt = $this->date((string) ($payload['validation_due_at'] ?? ''));
        $graceExpiresAt = $this->date((string) ($payload['grace_expires_at'] ?? ''));
        $decision = $this->states->interpret($status, $now, $expiresAt, $validationDueAt, $graceExpiresAt);

        $entitlements = $payload['entitlements'] ?? null;
        if (!is_array($entitlements) || !array_is_list($entitlements)) {
            throw ProtocolFailure::protocol('invalid_entitlements');
        }
        $limits = $this->integerPair($this->object($payload, 'activation_limits'));
        $usage = $this->integerPair($this->object($payload, 'activation_usage'));
        if ($usage['production'] > $limits['production'] || $usage['non_production'] > $limits['non_production']) {
            throw ProtocolFailure::protocol('invalid_activation_usage');
        }

        return new LicenceState(
            (string) ($payload['id'] ?? ''),
            $decision['status'],
            array_map('strval', $entitlements),
            $termStartedAt,
            $expiresAt,
            $validationDueAt,
            $graceExpiresAt,
            $limits,
            $usage,
            $decision['mode'],
            $decision['requires_action']
        );
    }

    /** @param array<string,mixed> $payload */
    private function decodeCredential(array $payload): ActivationCredential
    {
        return new ActivationCredential(
            (string) ($payload['activation_id'] ?? ''),
            (string) ($payload['activation_secret'] ?? '')
        );
    }

    /** @param mixed $payload */
    private function decodeUpdate(mixed $payload, Product $product): ?UpdateMetadata
    {
        if ($payload === null) {
            return null;
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw ProtocolFailure::protocol('invalid_update_metadata');
        }
        $update = new UpdateMetadata(
            (string) ($payload['release_id'] ?? ''),
            (string) ($payload['version'] ?? ''),
            (string) ($payload['package_identifier'] ?? ''),
            (string) ($payload['checksum_algorithm'] ?? ''),
            (string) ($payload['checksum'] ?? ''),
            isset($payload['minimum_php']) ? (string) $payload['minimum_php'] : null,
            isset($payload['minimum_runtime']) ? (string) $payload['minimum_runtime'] : null,
            isset($payload['published_at']) ? (string) $payload['published_at'] : null
        );
        if (!hash_equals($product->packageIdentifier(), $update->packageIdentifier())) {
            throw ProtocolFailure::authority('package_identifier_mismatch');
        }

        return $update;
    }

    /** @param array<string,mixed> $payload */
    private function decodePackage(
        array $payload,
        Product $product,
        TransportRequest $request,
        DateTimeImmutable $now
    ): PackageAuthorisation {
        if (!hash_equals($product->packageIdentifier(), trim((string) ($payload['package_identifier'] ?? '')))) {
            throw ProtocolFailure::authority('package_identifier_mismatch');
        }

        $requestPayload = Json::decode($request->body());
        $operation = $this->object($requestPayload, 'operation');
        $parameters = $this->object($operation, 'parameters');
        $releaseId = trim((string) ($payload['release_id'] ?? ''));
        $checksum = strtolower(trim((string) ($payload['checksum'] ?? '')));
        if (
            !hash_equals(trim((string) ($parameters['release_id'] ?? '')), $releaseId)
            || !hash_equals(strtolower(trim((string) ($parameters['checksum'] ?? ''))), $checksum)
        ) {
            throw ProtocolFailure::authority('package_release_mismatch');
        }

        $expiresAt = $this->date((string) ($payload['expires_at'] ?? ''));
        if ($expiresAt <= $now) {
            throw ProtocolFailure::authority('package_authorisation_expired');
        }

        return new PackageAuthorisation(
            $releaseId,
            (string) ($payload['download_uri'] ?? ''),
            (string) ($payload['package_token'] ?? ''),
            $expiresAt,
            $checksum
        );
    }

    private function decodeError(TransportResponse $response, string $requestId): ClientError
    {
        try {
            $payload = Json::decode($response->body());
            if (isset($payload['error'])) {
                return $this->errorFromEnvelope($payload, $requestId);
            }
        } catch (Throwable) {
        }

        $status = $response->status();
        $retryable = $status <= 0 || in_array($status, [408, 425, 429], true) || $status >= 500;

        return new ClientError(ErrorCategory::Server, $status > 0 ? 'http_' . $status : 'transport_failure', $retryable, $requestId);
    }

    /** @param array<string,mixed> $envelope */
    private function errorFromEnvelope(array $envelope, string $fallbackRequestId): ClientError
    {
        $error = $envelope['error'] ?? null;
        if (!is_array($error) || array_is_list($error)) {
            return new ClientError(ErrorCategory::Protocol, 'invalid_error_response', true, $fallbackRequestId);
        }
        $code = strtolower(trim((string) ($error['code'] ?? '')));
        if (preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $code) !== 1) {
            $code = 'invalid_error_response';
        }
        $category = ErrorCategory::tryFrom(strtolower(trim((string) ($error['category'] ?? ''))))
            ?? ErrorCategory::Server;
        $requestId = strtolower(trim((string) ($error['request_id'] ?? $fallbackRequestId)));

        return new ClientError($category, $code, (bool) ($error['retryable'] ?? false), $requestId);
    }

    private function failure(
        TransportRequest $request,
        ErrorCategory $category,
        string $code,
        bool $retryable = false
    ): OperationResult {
        return OperationResult::failure(
            $request->operation(),
            $request->requestId(),
            new ClientError($category, $code, $retryable, $request->requestId())
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function object(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw ProtocolFailure::protocol('invalid_' . $key);
        }

        return $value;
    }

    /** @param array<string,mixed> $payload @return array{production:int,non_production:int} */
    private function integerPair(array $payload): array
    {
        $production = filter_var($payload['production'] ?? null, FILTER_VALIDATE_INT);
        $nonProduction = filter_var($payload['non_production'] ?? null, FILTER_VALIDATE_INT);
        if ($production === false || $nonProduction === false || $production < 0 || $nonProduction < 0) {
            throw ProtocolFailure::protocol('invalid_activation_counts');
        }

        return ['production' => (int) $production, 'non_production' => (int) $nonProduction];
    }

    private function date(string $value): DateTimeImmutable
    {
        try {
            $date = new DateTimeImmutable(trim($value));
        } catch (Throwable) {
            throw ProtocolFailure::protocol('invalid_datetime');
        }
        if ($date->format(DATE_ATOM) !== trim($value)) {
            throw ProtocolFailure::protocol('invalid_datetime');
        }

        return $date;
    }
}
