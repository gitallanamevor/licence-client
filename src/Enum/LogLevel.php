<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Enum;

enum LogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
