<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\State;

use DateTimeImmutable;
use Zithis\LicenceClient\Enum\LicenceStatus;
use Zithis\LicenceClient\Enum\RuntimeMode;

final class StateInterpreter
{
    /** @return array{status:LicenceStatus,mode:RuntimeMode,requires_action:bool} */
    public function interpret(
        LicenceStatus $status,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $validationDueAt,
        DateTimeImmutable $graceExpiresAt
    ): array {
        if (in_array($status, [LicenceStatus::Suspended, LicenceStatus::Revoked, LicenceStatus::Deactivated], true)) {
            return ['status' => $status, 'mode' => RuntimeMode::Blocked, 'requires_action' => true];
        }
        if ($status === LicenceStatus::Expired || $now > $expiresAt) {
            return ['status' => LicenceStatus::Expired, 'mode' => RuntimeMode::Restricted, 'requires_action' => true];
        }
        if ($status === LicenceStatus::ValidationDue) {
            return ['status' => LicenceStatus::ValidationDue, 'mode' => RuntimeMode::Full, 'requires_action' => true];
        }
        if ($status === LicenceStatus::Grace) {
            return [
                'status' => LicenceStatus::Grace,
                'mode' => $now <= $graceExpiresAt ? RuntimeMode::Continuity : RuntimeMode::Restricted,
                'requires_action' => true,
            ];
        }
        if ($now > $validationDueAt && $now <= $graceExpiresAt) {
            return ['status' => LicenceStatus::Grace, 'mode' => RuntimeMode::Continuity, 'requires_action' => true];
        }
        if ($now > $graceExpiresAt) {
            return ['status' => LicenceStatus::Grace, 'mode' => RuntimeMode::Restricted, 'requires_action' => true];
        }

        return ['status' => LicenceStatus::Active, 'mode' => RuntimeMode::Full, 'requires_action' => false];
    }
}
