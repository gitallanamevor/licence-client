<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php\Update;

use RuntimeException;

final class StreamPackageTransport implements PackageTransport
{
    public function download(string $uri, string $destination, int $timeoutSeconds, int $maximumBytes): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Connection: close\r\nAccept-Encoding: identity",
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'timeout' => $timeoutSeconds,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
            ],
        ]);
        $source = @fopen($uri, 'rb', false, $context);
        if ($source === false) {
            throw new RuntimeException('The private application package could not be opened.');
        }
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})(?:\s|$)/i', (string) $line, $match) === 1) {
                    $status = (int) $match[1];
                }
            }
        }
        if ($status !== 200) {
            fclose($source);
            throw new RuntimeException('The private application package download failed.');
        }
        $target = fopen($destination, 'x+b');
        if ($target === false) {
            fclose($source);
            throw new RuntimeException('The staged application package could not be created.');
        }
        $bytes = 0;
        try {
            while (!feof($source)) {
                $chunk = fread($source, 65536);
                if (!is_string($chunk)) {
                    throw new RuntimeException('The private application package could not be read.');
                }
                if ($chunk === '') {
                    continue;
                }
                $bytes += strlen($chunk);
                if ($bytes > $maximumBytes) {
                    throw new RuntimeException('The private application package exceeded the configured size limit.');
                }
                $offset = 0;
                $length = strlen($chunk);
                while ($offset < $length) {
                    $written = fwrite($target, substr($chunk, $offset));
                    if (!is_int($written) || $written < 1) {
                        throw new RuntimeException('The staged application package could not be written.');
                    }
                    $offset += $written;
                }
            }
            if ($bytes < 1 || !fflush($target)) {
                throw new RuntimeException('The staged application package is empty or could not be flushed.');
            }
            if (function_exists('fsync')) {
                @fsync($target);
            }
            @chmod($destination, 0600);
        } catch (\Throwable $exception) {
            fclose($source);
            fclose($target);
            @unlink($destination);
            throw $exception;
        }
        fclose($source);
        fclose($target);
    }
}
