<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Runtime;

use DateTimeImmutable;
use Zithis\LicenceClient\Enum\LicenceStatus;
use Zithis\LicenceClient\Value\StoredState;

final class StatusResolver
{
    private const COMPATIBILITY_FAILURES = [
        'unsupported_client_version',
        'unsupported_server_version',
        'unsupported_protocol_version',
        'unsupported_contract_version',
    ];

    /**
     * @param array{last_validated_at:?string,failure_code:?string,failed_at:?string,temporary_failure:bool} $validation
     */
    public function resolve(?StoredState $stored, array $validation, DateTimeImmutable $now): Status
    {
        if ($stored === null) {
            return new Status(Status::UNCONFIGURED);
        }

        $state = $stored->licence();
        $direct = match ($state->status()) {
            LicenceStatus::Expired => Status::EXPIRED,
            LicenceStatus::Suspended => Status::SUSPENDED,
            LicenceStatus::Revoked => Status::REVOKED,
            LicenceStatus::Deactivated => Status::INSTALLATION_INACTIVE,
            LicenceStatus::Grace => Status::VALIDATION_GRACE,
            LicenceStatus::ValidationDue => Status::VALIDATION_DUE,
            LicenceStatus::Active => null,
        };
        if ($direct !== null && !in_array($direct, [Status::VALIDATION_GRACE, Status::VALIDATION_DUE], true)) {
            return new Status($direct);
        }
        if ($now > $state->expiresAt()) {
            return new Status(Status::EXPIRED);
        }

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
