<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Value;

use Zithis\LicenceClient\Enum\Operation;

final class OperationResult
{
    private function __construct(
        private bool $successful,
        private Operation $operation,
        private ?LicenceState $licence,
        private ?ActivationCredential $credential,
        private ?UpdateMetadata $update,
        private ?PackageAuthorisation $package,
        private ?ClientError $error,
        private string $requestId
    ) {
    }

    public static function success(
        Operation $operation,
        string $requestId,
        ?LicenceState $licence = null,
        ?ActivationCredential $credential = null,
        ?UpdateMetadata $update = null,
        ?PackageAuthorisation $package = null
    ): self {
        return new self(true, $operation, $licence, $credential, $update, $package, null, $requestId);
    }

    public static function failure(Operation $operation, string $requestId, ClientError $error): self
    {
        return new self(false, $operation, null, null, null, null, $error, $requestId);
    }

    public function successful(): bool { return $this->successful; }
    public function operation(): Operation { return $this->operation; }
    public function licence(): ?LicenceState { return $this->licence; }
    public function credential(): ?ActivationCredential { return $this->credential; }
    public function update(): ?UpdateMetadata { return $this->update; }
    public function package(): ?PackageAuthorisation { return $this->package; }
    public function error(): ?ClientError { return $this->error; }
    public function requestId(): string { return $this->requestId; }
}
