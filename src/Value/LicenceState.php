<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Value;

use DateTimeImmutable;
use InvalidArgumentException;
use Zithis\LicenceClient\Enum\LicenceStatus;
use Zithis\LicenceClient\Enum\RuntimeMode;

final class LicenceState
{
    /** @var list<string> */
    private array $entitlements;

    /**
     * @param list<string> $entitlements
     * @param array{production:int,non_production:int} $activationLimits
     * @param array{production:int,non_production:int} $activationUsage
     */
    public function __construct(
        private string $id,
        private LicenceStatus $status,
        array $entitlements,
        private DateTimeImmutable $termStartedAt,
        private DateTimeImmutable $expiresAt,
        private DateTimeImmutable $validationDueAt,
        private DateTimeImmutable $graceExpiresAt,
        private array $activationLimits,
        private array $activationUsage,
        private RuntimeMode $runtimeMode,
        private bool $requiresAction
    ) {
        $this->id = trim($this->id);
        if ($this->id === '' || strlen($this->id) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $this->id) !== 1) {
            throw new InvalidArgumentException('The licence identifier is invalid.');
        }
        if ($this->expiresAt < $this->termStartedAt || $this->graceExpiresAt < $this->validationDueAt) {
            throw new InvalidArgumentException('The licence date window is invalid.');
        }
        $normalized = [];
        foreach ($entitlements as $entitlement) {
            $entitlement = strtolower(trim($entitlement));
            if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $entitlement) !== 1) {
                throw new InvalidArgumentException('A licence entitlement is invalid.');
            }
            $normalized[$entitlement] = true;
        }
        $this->entitlements = array_keys($normalized);
        foreach (['production', 'non_production'] as $key) {
            if (($this->activationLimits[$key] ?? -1) < 0 || ($this->activationUsage[$key] ?? -1) < 0) {
                throw new InvalidArgumentException('Licence activation usage and limits cannot be negative.');
            }
        }
    }

    public function id(): string { return $this->id; }
    public function status(): LicenceStatus { return $this->status; }
    /** @return list<string> */
    public function entitlements(): array { return $this->entitlements; }
    public function termStartedAt(): DateTimeImmutable { return $this->termStartedAt; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function validationDueAt(): DateTimeImmutable { return $this->validationDueAt; }
    public function graceExpiresAt(): DateTimeImmutable { return $this->graceExpiresAt; }
    /** @return array{production:int,non_production:int} */
    public function activationLimits(): array { return $this->activationLimits; }
    /** @return array{production:int,non_production:int} */
    public function activationUsage(): array { return $this->activationUsage; }
    public function runtimeMode(): RuntimeMode { return $this->runtimeMode; }
    public function requiresAction(): bool { return $this->requiresAction; }


    public function equivalentTo(self $other): bool
    {
        $entitlements = $this->entitlements;
        $otherEntitlements = $other->entitlements;
        sort($entitlements, SORT_STRING);
        sort($otherEntitlements, SORT_STRING);

        return hash_equals($this->id, $other->id)
            && $this->status === $other->status
            && $entitlements === $otherEntitlements
            && $this->termStartedAt->getTimestamp() === $other->termStartedAt->getTimestamp()
            && $this->expiresAt->getTimestamp() === $other->expiresAt->getTimestamp()
            && $this->validationDueAt->getTimestamp() === $other->validationDueAt->getTimestamp()
            && $this->graceExpiresAt->getTimestamp() === $other->graceExpiresAt->getTimestamp()
            && $this->activationLimits === $other->activationLimits
            && $this->activationUsage === $other->activationUsage
            && $this->runtimeMode === $other->runtimeMode
            && $this->requiresAction === $other->requiresAction;
    }

    public function hasEntitlement(string $entitlement): bool
    {
        return in_array(strtolower(trim($entitlement)), $this->entitlements, true);
    }
}
