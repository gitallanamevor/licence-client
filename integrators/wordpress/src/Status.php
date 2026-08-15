<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator;

final class Status
{
    public const UNCONFIGURED = 'unconfigured';
    public const ACTIVE = 'active';
    public const VALIDATION_DUE = 'validation_due';
    public const VALIDATION_GRACE = 'validation_grace';
    public const VALIDATION_FAILED = 'validation_failed';
    public const EXPIRED = 'expired';
    public const SUSPENDED = 'suspended';
    public const REVOKED = 'revoked';
    public const SITE_INACTIVE = 'site_inactive';
    public const LOCAL_STORAGE_UNAVAILABLE = 'local_storage_unavailable';
    public const INCOMPATIBLE = 'incompatible';

    public function __construct(private string $code, private ?string $detail = null)
    {
    }

    public function code(): string { return $this->code; }
    public function detail(): ?string { return $this->detail; }
}
