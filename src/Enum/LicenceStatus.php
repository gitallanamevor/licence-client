<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Enum;

enum LicenceStatus: string
{
    case Active = 'active';
    case ValidationDue = 'validation_due';
    case Grace = 'grace';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
    case Deactivated = 'deactivated';
}
