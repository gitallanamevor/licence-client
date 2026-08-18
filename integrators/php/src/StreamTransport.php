<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php;

use RuntimeException;
use Zithis\LicenceClient\Contract\Transport;
use Zithis\LicenceClient\Value\TransportRequest;
use Zithis\LicenceClient\Value\TransportResponse;

final class StreamTransport implements Transport
{
    public function __construct(private int $timeoutSeconds, private int $maximumResponseBytes)
    {
        $this->timeoutSeconds = max(5, min($this->timeoutSeconds, 30));
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $headers = [];
        foreach ($request->headers() as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+$/', (string) $name) !== 1 || preg_match('/[\r\n]/', (string) $value) === 1) {
                throw new RuntimeException('A licence transport header is invalid.');
            }
            $headers[] = $name . ': ' . $value;
        }
        $headers[] = 'Connection: close';
        $headers[] = 'Accept-Encoding: identity';

        $context = stream_context_create([
            'http' => [
                'method' => $request->method(),
                'header' => implode("\r\n", $headers),
                'content' => $request->body(),
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'timeout' => $this->timeoutSeconds,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
            ],
        ]);
        $handle = @fopen($request->uri(), 'rb', false, $context);
        if ($handle === false) {
            throw new RuntimeException('The LicenceServer request could not be opened.');
        }
        try {
            $body = stream_get_contents($handle, $this->maximumResponseBytes + 1);
            if (!is_string($body)) {
                throw new RuntimeException('The LicenceServer response could not be read.');
            }
            if (strlen($body) > $this->maximumResponseBytes) {
                throw new RuntimeException('The LicenceServer response exceeded the configured size limit.');
            }
        } finally {
            fclose($handle);
        }

        $responseHeaders = isset($http_response_header) && is_array($http_response_header)
            ? array_map('strval', $http_response_header)
            : [];
        [$status, $parsedHeaders] = $this->parseHeaders($responseHeaders);

        return new TransportResponse($status, $parsedHeaders, $body);
    }

    /** @param list<string> $lines
     *  @return array{0:int,1:array<string,string|list<string>>}
     */
    private function parseHeaders(array $lines): array
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})(?:\s|$)/i', $line, $match) === 1) {
                $status = (int) $match[1];
                $headers = [];
                continue;
            }
            $position = strpos($line, ':');
            if ($position === false) {
                continue;
            }
            $name = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));
            if ($name === '' || preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1) {
                continue;
            }
            if (!array_key_exists($name, $headers)) {
                $headers[$name] = $value;
            } elseif (is_array($headers[$name])) {
                $headers[$name][] = $value;
            } else {
                $headers[$name] = [(string) $headers[$name], $value];
            }
        }
        if ($status < 100 || $status > 599) {
            throw new RuntimeException('The LicenceServer response status is invalid.');
        }

        return [$status, $headers];
    }
}
