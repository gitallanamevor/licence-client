<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator;

use Throwable;
use Zithis\LicenceClient\Contract\Clock;
use Zithis\LicenceClient\Enum\RuntimeMode;
use Zithis\LicenceClient\LicenceClient;
use Zithis\LicenceClient\Runtime\Status;
use Zithis\LicenceClient\Runtime\StatusResolver;
use Zithis\LicenceClient\Value\LicenceState;
use Zithis\LicenceClient\Value\OperationResult;
use Zithis\LicenceClient\Value\UpdateMetadata;
use Zithis\StandaloneWordPressIntegrator\Storage\WordPressCredentialStore;

final class LicenceManager
{
    private const REACTIVATION_REQUIRED = [
        'activation_required',
        'activation_token_missing',
        'invalid_activation',
        'installation_mismatch',
        'installation_not_active',
        'product_mismatch',
        'package_identifier_mismatch',
    ];

    public function __construct(
        private Configuration $configuration,
        private LicenceClient $client,
        private WordPressCredentialStore $store,
        private Clock $clock,
        private StatusResolver $statuses
    ) {
    }

    public function activate(string $licenceKey): OperationResult
    {
        $result = $this->client->activate(trim($licenceKey));
        $this->after($result, false);

        return $result;
    }

    public function validate(bool $force = true): OperationResult
    {
        if (!$force) {
            try {
                $state = $this->store->load($this->configuration->productCode())?->licence();
                $validation = $this->store->validation();
                if ($state !== null
                    && $validation['failure_code'] === null
                    && time() < $state->validationDueAt()->getTimestamp()) {
                    return OperationResult::success(
                        \Zithis\LicenceClient\Enum\Operation::Validate,
                        'local-' . bin2hex(random_bytes(8)),
                        $state
                    );
                }
            } catch (Throwable) {
            }
        }

        $result = $this->client->validate();
        $this->after($result, true);

        return $result;
    }

    public function deactivate(): OperationResult
    {
        $result = $this->client->deactivate();
        $this->after($result, false);

        return $result;
    }

    public function checkForUpdate(): OperationResult
    {
        $result = $this->client->checkForUpdate();
        $this->after($result, false);

        return $result;
    }

    public function authorizePackage(UpdateMetadata $update): OperationResult
    {
        $result = $this->client->authorizePackage($update);
        $this->after($result, false);

        return $result;
    }

    public function forgetCachedState(): void
    {
        $this->store->forgetCachedState();
    }

    public function current(): ?LicenceState
    {
        try {
            return $this->store->load($this->configuration->productCode())?->licence();
        } catch (Throwable) {
            return null;
        }
    }

    public function configured(): bool
    {
        try {
            return $this->store->configured();
        } catch (Throwable) {
            return false;
        }
    }

    public function status(): Status
    {
        try {
            return $this->statuses->resolve(
                $this->store->load($this->configuration->productCode()),
                $this->store->validation(),
                $this->clock->now()
            );
        } catch (Throwable) {
            return new Status(Status::LOCAL_STORAGE_UNAVAILABLE);
        }
    }

    public function hasEntitlement(string $entitlement): bool
    {
        $entitlement = strtolower(trim($entitlement));
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $entitlement) !== 1) {
            return false;
        }
        $state = $this->current();
        if ($state === null || !$state->hasEntitlement($entitlement)) {
            return false;
        }
        $status = $this->status()->code();
        if ($entitlement === 'suite') {
            return in_array($state->runtimeMode(), [RuntimeMode::Full, RuntimeMode::Continuity], true)
                && in_array($status, [
                    Status::ACTIVE,
                    Status::VALIDATION_DUE,
                    Status::VALIDATION_GRACE,
                    Status::EXPIRED,
                ], true);
        }

        return $state->runtimeMode() === RuntimeMode::Full
            && in_array($status, [Status::ACTIVE, Status::VALIDATION_DUE], true);
    }

    public function canUseBusinessRuntime(): bool
    {
        return $this->hasEntitlement('suite');
    }

    public function canReceiveUpdates(): bool
    {
        return $this->hasEntitlement('updates');
    }

    private function after(OperationResult $result, bool $recordValidationFailure): void
    {
        if (!$result->successful()) {
            $code = $result->error()?->code() ?: 'invalid_response';
            if (in_array(strtolower($code), self::REACTIVATION_REQUIRED, true)) {
                try {
                    $this->store->clear($this->configuration->productCode());
                } catch (Throwable) {
                }
            } elseif ($recordValidationFailure) {
                try {
                    $this->store->recordValidationFailure($code, $result->error()?->retryable() ?? false);
                } catch (Throwable) {
                }
            }
        }
        if (function_exists('do_action')) {
            do_action($this->configuration->stateChangedHook(), $this->configuration->productCode(), $result);
        }
    }
}
