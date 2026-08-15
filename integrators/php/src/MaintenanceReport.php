<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php;

final class MaintenanceReport
{
    public function __construct(
        private bool $lockAcquired,
        private bool $validationAttempted,
        private ?bool $validationSuccessful,
        private bool $updateAttempted,
        private ?bool $updateSuccessful
    ) {
    }

    public function lockAcquired(): bool { return $this->lockAcquired; }
    public function validationAttempted(): bool { return $this->validationAttempted; }
    public function validationSuccessful(): ?bool { return $this->validationSuccessful; }
    public function updateAttempted(): bool { return $this->updateAttempted; }
    public function updateSuccessful(): ?bool { return $this->updateSuccessful; }

    /** @return array<string,bool|null> */
    public function toArray(): array
    {
        return [
            'lock_acquired' => $this->lockAcquired,
            'validation_attempted' => $this->validationAttempted,
            'validation_successful' => $this->validationSuccessful,
            'update_attempted' => $this->updateAttempted,
            'update_successful' => $this->updateSuccessful,
        ];
    }
}
