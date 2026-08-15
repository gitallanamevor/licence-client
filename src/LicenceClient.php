<?php

declare(strict_types=1);

namespace Zithis\LicenceClient;

use Throwable;
use Zithis\LicenceClient\Contract\Clock;
use Zithis\LicenceClient\Contract\CredentialStore;
use Zithis\LicenceClient\Contract\InstallationIdentity;
use Zithis\LicenceClient\Contract\Logger;
use Zithis\LicenceClient\Contract\ProductDescriptor;
use Zithis\LicenceClient\Contract\Transport;
use Zithis\LicenceClient\Enum\ErrorCategory;
use Zithis\LicenceClient\Enum\LogLevel;
use Zithis\LicenceClient\Enum\Operation;
use Zithis\LicenceClient\Exception\TransportFailure;
use Zithis\LicenceClient\Protocol\RequestFactory;
use Zithis\LicenceClient\Protocol\ResponseDecoder;
use Zithis\LicenceClient\Security\Redactor;
use Zithis\LicenceClient\Value\ActivationCredential;
use Zithis\LicenceClient\Value\Authority;
use Zithis\LicenceClient\Value\ClientError;
use Zithis\LicenceClient\Value\EndpointSet;
use Zithis\LicenceClient\Value\OperationResult;
use Zithis\LicenceClient\Value\Product;
use Zithis\LicenceClient\Value\StoredState;
use Zithis\LicenceClient\Value\TransportRequest;
use Zithis\LicenceClient\Value\UpdateMetadata;

final class LicenceClient
{
    public function __construct(
        private Transport $transport,
        private CredentialStore $store,
        private InstallationIdentity $identity,
        private Clock $clock,
        private Logger $logger,
        ProductDescriptor $product,
        private EndpointSet $endpoints,
        private Authority $authority,
        private RequestFactory $requests,
        private ResponseDecoder $responses,
        private Redactor $redactor
    ) {
        $this->product = Product::fromDescriptor($product);
    }

    private Product $product;

    public function activate(string $licenceKey): OperationResult
    {
        $licenceKey = trim($licenceKey);
        if ($licenceKey === '') {
            return $this->localFailure(Operation::Activate, 'licence_key_required');
        }

        $request = $this->requests->create(
            Operation::Activate,
            $this->endpoints,
            $this->product,
            $this->identity->installation(),
            $this->clock->now(),
            ['licence_key' => $licenceKey]
        );
        $result = $this->execute($request);
        if ($result->successful() && $result->credential() !== null && $result->licence() !== null) {
            try {
                $this->store->save(
                    $this->product->code(),
                    new StoredState($result->credential(), $result->licence())
                );
            } catch (Throwable) {
                return $this->failureForRequest($request, ErrorCategory::LocalState, 'credential_store_failed');
            }
        }

        return $result;
    }

    public function validate(): OperationResult
    {
        return $this->authenticated(Operation::Validate);
    }

    public function deactivate(): OperationResult
    {
        $result = $this->authenticated(Operation::Deactivate);
        if ($this->requiresDeactivationReconciliation($result)) {
            $validation = $this->authenticated(Operation::Validate);
            if ($this->confirmsRemoteDeactivation($validation)) {
                $this->log(LogLevel::Info, 'licence_deactivation_reconciled', [
                    'operation' => Operation::Deactivate->value,
                    'request_id' => $result->requestId(),
                    'product_code' => $this->product->code(),
                    'confirmation_code' => $validation->error()?->code(),
                ]);
                $result = OperationResult::success(Operation::Deactivate, $result->requestId());
            }
        }

        if ($result->successful()) {
            try {
                $this->store->clear($this->product->code());
            } catch (Throwable) {
                return OperationResult::failure(
                    Operation::Deactivate,
                    $result->requestId(),
                    new ClientError(ErrorCategory::LocalState, 'credential_clear_failed', false, $result->requestId())
                );
            }
        }

        return $result;
    }

    public function checkForUpdate(): OperationResult
    {
        return $this->authenticated(Operation::UpdateCheck, [
            'installed_version' => $this->product->installedVersion(),
        ]);
    }

    public function authorizePackage(UpdateMetadata $update): OperationResult
    {
        if (!hash_equals($this->product->packageIdentifier(), $update->packageIdentifier())) {
            return $this->localFailure(Operation::PackageAuthorisation, 'package_identifier_mismatch');
        }

        return $this->authenticated(Operation::PackageAuthorisation, [
            'release_id' => $update->releaseId(),
            'version' => $update->version(),
            'checksum_algorithm' => $update->checksumAlgorithm(),
            'checksum' => $update->checksum(),
        ]);
    }

    /** @param array<string,mixed> $parameters */
    private function authenticated(Operation $operation, array $parameters = []): OperationResult
    {
        try {
            $stored = $this->store->load($this->product->code());
        } catch (Throwable) {
            return $this->localFailure($operation, 'credential_store_failed');
        }
        if (!$stored instanceof StoredState) {
            return $this->localFailure($operation, 'activation_required');
        }

        $request = $this->requests->create(
            $operation,
            $this->endpoints,
            $this->product,
            $this->identity->installation(),
            $this->clock->now(),
            $parameters,
            $stored->credential()
        );
        $result = $this->execute($request);
        if ($result->successful() && $result->licence() !== null && $operation !== Operation::Deactivate) {
            try {
                $this->store->save(
                    $this->product->code(),
                    new StoredState($stored->credential(), $result->licence())
                );
            } catch (Throwable) {
                return $this->failureForRequest($request, ErrorCategory::LocalState, 'state_store_failed');
            }
        }

        return $result;
    }

    private function requiresDeactivationReconciliation(OperationResult $result): bool
    {
        $category = $result->error()?->category();

        return !$result->successful()
            && ($result->error()?->retryable() ?? false)
            && in_array($category, [
                ErrorCategory::Transport,
                ErrorCategory::Protocol,
                ErrorCategory::Server,
            ], true);
    }

    private function confirmsRemoteDeactivation(OperationResult $validation): bool
    {
        $code = strtolower(trim((string) $validation->error()?->code()));

        if ($validation->successful()) {
            return false;
        }

        return match ($code) {
            'invalid_activation' => $validation->error()?->category() === ErrorCategory::Credential,
            'installation_not_active' => in_array($validation->error()?->category(), [
                ErrorCategory::Credential,
                ErrorCategory::Server,
            ], true),
            default => false,
        };
    }

    private function execute(TransportRequest $request): OperationResult
    {
        $this->log(LogLevel::Info, 'licence_operation_started', [
            'operation' => $request->operation()->value,
            'request_id' => $request->requestId(),
            'product_code' => $this->product->code(),
        ]);

        try {
            $response = $this->transport->send($request);
        } catch (Throwable $exception) {
            $failureCode = $exception instanceof TransportFailure
                ? $exception->failureCode()
                : 'transport_failure';
            $this->log(LogLevel::Warning, 'licence_transport_failed', [
                'operation' => $request->operation()->value,
                'request_id' => $request->requestId(),
                'exception' => (new \ReflectionClass($exception))->getShortName(),
                'failure_code' => $failureCode,
            ]);

            return $this->failureForRequest($request, ErrorCategory::Transport, $failureCode, true);
        }

        $result = $this->responses->decode(
            $request,
            $response,
            $this->product,
            $this->identity->installation(),
            $this->authority,
            $this->clock->now()
        );
        $this->log(
            $result->successful() ? LogLevel::Info : LogLevel::Warning,
            $result->successful() ? 'licence_operation_succeeded' : 'licence_operation_failed',
            [
                'operation' => $request->operation()->value,
                'request_id' => $request->requestId(),
                'error_code' => $result->error()?->code(),
                'retryable' => $result->error()?->retryable(),
            ]
        );

        return $result;
    }

    private function localFailure(Operation $operation, string $code): OperationResult
    {
        $requestId = 'local-' . bin2hex(random_bytes(8));

        return OperationResult::failure(
            $operation,
            $requestId,
            new ClientError(ErrorCategory::LocalState, $code, false, $requestId)
        );
    }

    private function failureForRequest(
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

    /** @param array<string,mixed> $context */
    private function log(LogLevel $level, string $message, array $context): void
    {
        try {
            $this->logger->log($level, $message, $this->redactor->context($context));
        } catch (Throwable) {
        }
    }
}
