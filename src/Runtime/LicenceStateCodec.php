<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Runtime;

use DateTimeImmutable;
use RuntimeException;
use Zithis\LicenceClient\Enum\LicenceStatus;
use Zithis\LicenceClient\Enum\RuntimeMode;
use Zithis\LicenceClient\Value\LicenceState;

final class LicenceStateCodec
{
    /** @return array<string,mixed> */
    public function encode(LicenceState $state): array
    {
        return [
            'id' => $state->id(),
            'status' => $state->status()->value,
            'entitlements' => $state->entitlements(),
            'term_started_at' => $state->termStartedAt()->format(DATE_ATOM),
            'expires_at' => $state->expiresAt()->format(DATE_ATOM),
            'validation_due_at' => $state->validationDueAt()->format(DATE_ATOM),
            'grace_expires_at' => $state->graceExpiresAt()->format(DATE_ATOM),
            'activation_limits' => $state->activationLimits(),
            'activation_usage' => $state->activationUsage(),
            'runtime_mode' => $state->runtimeMode()->value,
            'requires_action' => $state->requiresAction(),
        ];
    }

    /** @param array<string,mixed> $payload */
    public function decode(array $payload): LicenceState
    {
        $limits = is_array($payload['activation_limits'] ?? null) ? $payload['activation_limits'] : [];
        $usage = is_array($payload['activation_usage'] ?? null) ? $payload['activation_usage'] : [];

        return new LicenceState(
            (string) ($payload['id'] ?? ''),
            LicenceStatus::from((string) ($payload['status'] ?? '')),
            is_array($payload['entitlements'] ?? null) ? array_map('strval', $payload['entitlements']) : [],
            $this->date((string) ($payload['term_started_at'] ?? '')),
            $this->date((string) ($payload['expires_at'] ?? '')),
            $this->date((string) ($payload['validation_due_at'] ?? '')),
            $this->date((string) ($payload['grace_expires_at'] ?? '')),
            [
                'production' => (int) ($limits['production'] ?? -1),
                'non_production' => (int) ($limits['non_production'] ?? -1),
            ],
            [
                'production' => (int) ($usage['production'] ?? -1),
                'non_production' => (int) ($usage['non_production'] ?? -1),
            ],
            RuntimeMode::from((string) ($payload['runtime_mode'] ?? '')),
            ($payload['requires_action'] ?? false) === true
        );
    }

    private function date(string $value): DateTimeImmutable
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        if (!$date instanceof DateTimeImmutable || $date->format(DATE_ATOM) !== $value) {
            throw new RuntimeException('A stored licence date is invalid.');
        }

        return $date;
    }
}
