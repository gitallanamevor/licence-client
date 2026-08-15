<?php

declare(strict_types=1);

namespace Zithis\LicenceClient;

final class ClientPackage
{
    public const NAME = 'zithis/licence-client';
    public const VERSION = '1.1.6';
    public const PROTOCOL_VERSION = '1.0';

    /** @return list<string> */
    public static function supportedProtocolVersions(): array
    {
        return [self::PROTOCOL_VERSION];
    }

    private function __construct()
    {
    }
}
