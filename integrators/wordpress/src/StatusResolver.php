<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator;

use Throwable;
use Zithis\LicenceClient\Contract\Clock;
use Zithis\LicenceClient\Enum\LicenceStatus;
use Zithis\StandaloneWordPressIntegrator\Storage\WordPressCredentialStore;

final class StatusResolver
{
    private const COMPATIBILITY_FAILURES = [
        'unsupported_client_version',
        'unsupported_server_version',
        'unsupported_protocol_version',
        'unsupported_contract_version',
    ];

    public function __construct(
        private Configuration $configuration,
        private WordPressCredentialStore $store,
        private Clock $clock
    ) {
    }

    public function resolve(): Status
    {
        try {
            if (!$this->store->configured()) {
                return new Status(Status::UNCONFIGURED);
            }
            $stored = $this->store->load($this->configuration->productCode());
        } catch (Throwable) {
            return new Status(Status::LOCAL_STORAGE_UNAVAILABLE);
        }
        if ($stored === null) {
            return new Status(Status::UNCONFIGURED);
        }

        $state = $stored->licence();
        $direct = match ($state->status()) {
            LicenceStatus::Expired => Status::EXPIRED,
            LicenceStatus::Suspended => Status::SUSPENDED,
            LicenceStatus::Revoked => Status::REVOKED,
            LicenceStatus::Deactivated => Status::SITE_INACTIVE,
            LicenceStatus::Grace => Status::VALIDATION_GRACE,
            LicenceStatus::ValidationDue => Status::VALIDATION_DUE,
            LicenceStatus::Active => null,
        };
        if ($direct !== null && $direct !== Status::VALIDATION_GRACE && $direct !== Status::VALIDATION_DUE) {
            return new Status($direct);
        }
        $now = $this->clock->now();
        if ($now > $state->expiresAt()) {
            return new Status(Status::EXPIRED);
        }

        $validation = $this->store->validation();
        $failure = $validation['failure_code'];
        if ($failure !== null) {
            if (in_array($failure, self::COMPATIBILITY_FAILURES, true)) {
                return new Status(Status::INCOMPATIBLE, $failure);
            }
            if ($validation['temporary_failure'] && $now <= $state->graceExpiresAt()) {
                return new Status(Status::VALIDATION_GRACE, $failure);
            }

            return new Status(Status::VALIDATION_FAILED, $failure);
        }
        if ($direct === Status::VALIDATION_GRACE) {
            return new Status(Status::VALIDATION_GRACE);
        }
        if ($now >= $state->validationDueAt()) {
            return new Status(Status::VALIDATION_DUE);
        }

        return new Status(Status::ACTIVE);
    }
}
