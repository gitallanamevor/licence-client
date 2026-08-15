<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator;

use RuntimeException;

final class Plugin
{
    private static ?Runtime $runtime = null;
    private static ?string $pluginFile = null;

    public static function register(string $pluginFile, callable $businessBootstrap): Runtime
    {
        $real = realpath($pluginFile);
        if ($real === false || !is_file($real)) {
            throw new RuntimeException('The standalone WordPress plugin bootstrap file is invalid.');
        }
        if (self::$runtime instanceof Runtime) {
            if (!hash_equals((string) self::$pluginFile, $real)) {
                throw new RuntimeException('The generated standalone WordPress integrator is already registered to another plugin.');
            }

            return self::$runtime;
        }

        self::$pluginFile = $real;
        self::$runtime = Runtime::create($real, $businessBootstrap)->register();

        return self::$runtime;
    }

    public static function runtime(): ?Runtime
    {
        return self::$runtime;
    }

    private function __construct()
    {
    }
}
