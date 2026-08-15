<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php\Console;

use RuntimeException;
use Zithis\LicenceClient\Integrator\Php\ApplicationRuntime;
use Zithis\LicenceClient\Value\OperationResult;

final class ConsoleApplication
{
    public function run(ApplicationRuntime $runtime, array $arguments): int
    {
        $command = strtolower(trim((string) ($arguments[0] ?? 'status')));
        return match ($command) {
            'status' => $this->output(['status' => $runtime->status()->code(), 'detail' => $runtime->status()->detail()]),
            'activate' => $this->operation($runtime->licences()->activate($this->licenceKey())),
            'validate' => $this->operation($runtime->licences()->validate(true)),
            'deactivate' => $this->operation($runtime->licences()->deactivate()),
            'check-update' => $this->checkUpdate($runtime),
            'stage-update' => $this->stage($runtime, (string) ($arguments[1] ?? '')),
            'maintenance' => $this->output($runtime->maintenance()->run()->toArray()),
            default => throw new RuntimeException('Unknown command. Use status, activate, validate, deactivate, check-update, stage-update or maintenance.'),
        };
    }

    private function checkUpdate(ApplicationRuntime $runtime): int
    {
        $successful = $runtime->updates()->discover(true);

        return $this->output([
            'successful' => $successful,
            'available_version' => $runtime->updates()->offer()?->version(),
        ], $successful ? 0 : 1);
    }

    private function operation(OperationResult $result): int
    {
        return $this->output([
            'successful' => $result->successful(),
            'operation' => $result->operation()->value,
            'request_id' => $result->requestId(),
            'error_code' => $result->error()?->code(),
        ], $result->successful() ? 0 : 1);
    }

    private function stage(ApplicationRuntime $runtime, string $directory): int
    {
        $directory = trim($directory);
        if ($directory === '') {
            throw new RuntimeException('The stage-update command requires a destination directory.');
        }
        $offer = $runtime->updates()->offer();
        if ($offer === null) {
            $runtime->updates()->discover(true);
            $offer = $runtime->updates()->offer();
        }
        if ($offer === null) {
            return $this->output(['successful' => false, 'error_code' => 'update_not_available'], 1);
        }
        $package = $runtime->updates()->stage($offer, $directory);

        return $this->output([
            'successful' => true,
            'release_id' => $package->releaseId(),
            'version' => $package->version(),
            'checksum' => $package->checksum(),
            'path' => $package->path(),
        ]);
    }

    private function licenceKey(): string
    {
        if (defined('STDOUT')) {
            fwrite(STDOUT, "Licence key: ");
        }
        $value = defined('STDIN') ? fgets(STDIN) : false;
        $key = is_string($value) ? trim($value) : '';
        if ($key === '') {
            throw new RuntimeException('A licence key is required on standard input.');
        }

        return $key;
    }

    /** @param array<string,mixed> $payload */
    private function output(array $payload, int $code = 0): int
    {
        fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

        return $code;
    }
}
