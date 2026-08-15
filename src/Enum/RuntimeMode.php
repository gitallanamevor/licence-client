<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Enum;

enum RuntimeMode: string
{
    case Full = 'full';
    case Continuity = 'continuity';
    case Restricted = 'restricted';
    case Blocked = 'blocked';
}
