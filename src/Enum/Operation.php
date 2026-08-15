<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Enum;

enum Operation: string
{
    case Activate = 'activate';
    case Validate = 'validate';
    case Deactivate = 'deactivate';
    case UpdateCheck = 'update_check';
    case PackageAuthorisation = 'package_authorisation';
}
