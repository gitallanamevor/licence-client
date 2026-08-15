<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Enum;

enum ErrorCategory: string
{
    case Transport = 'transport';
    case Protocol = 'protocol';
    case Compatibility = 'compatibility';
    case Authority = 'authority';
    case Credential = 'credential';
    case LocalState = 'local_state';
    case Server = 'server';
}
