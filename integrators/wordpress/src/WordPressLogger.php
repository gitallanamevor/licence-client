<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator;

use Throwable;
use Zithis\LicenceClient\Contract\Logger;
use Zithis\LicenceClient\Enum\LogLevel;

final class WordPressLogger implements Logger
{
    public function __construct(private Configuration $configuration)
    {
    }

    /** @param array<string,mixed> $context */
    public function log(LogLevel $level, string $message, array $context = []): void
    {
        if (!in_array($level, [LogLevel::Warning, LogLevel::Error], true)) {
            return;
        }

        try {
            $payload = json_encode([
                'product_code' => $this->configuration->productCode(),
                'level' => $level->value,
                'message' => preg_replace('/[^a-z0-9._-]/i', '_', $message) ?: 'licence_event',
                'context' => $context,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            error_log('[Zithis Licence] ' . $payload);
        } catch (Throwable) {
        }
    }
}
