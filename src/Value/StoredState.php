<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Value;

final class StoredState
{
    public function __construct(
        private ActivationCredential $credential,
        private LicenceState $licence
    ) {
    }

    public function credential(): ActivationCredential { return $this->credential; }
    public function licence(): LicenceState { return $this->licence; }

    public function equivalentTo(self $other): bool
    {
        return hash_equals($this->credential->id(), $other->credential->id())
            && hash_equals($this->credential->secret(), $other->credential->secret())
            && $this->licence->equivalentTo($other->licence);
    }
}
