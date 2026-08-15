<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator\Http;

use RuntimeException;
use Zithis\LicenceClient\Exception\TransportFailure;
use Zithis\StandaloneWordPressIntegrator\Configuration;

final class AuthorityHttpPolicy
{
    public function __construct(private Configuration $configuration)
    {
    }

    public function licenceRequest(string $url, callable $request): mixed
    {
        $normalized = rtrim(trim($url), '/');
        $allowed = false;
        foreach ($this->configuration->endpoints() as $endpoint) {
            if (hash_equals(rtrim($endpoint, '/'), $normalized)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new TransportFailure('authority_endpoint_not_allowed');
        }

        return $this->request($url, $request);
    }

    public function packageRequest(string $url, callable $request): mixed
    {
        $this->assertPackageDownloadUri($url);

        return $this->request($url, $request);
    }

    public function assertPackageDownloadUri(string $url): void
    {
        $parts = $this->urlParts($url);
        $host = $this->host($parts);
        if (!in_array($host, $this->configuration->packageDownloadHosts(), true)) {
            throw new RuntimeException('The private package host is not authorised.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https' && !($this->configuration->allowsPrivateNetwork() && $scheme === 'http')) {
            throw new RuntimeException('The private package transport is not authorised.');
        }
    }

    private function request(string $url, callable $request): mixed
    {
        if (!$this->configuration->allowsPrivateNetwork()) {
            return $request();
        }
        if (!function_exists('add_filter') || !function_exists('remove_filter')) {
            throw new TransportFailure('wordpress_filter_api_unavailable');
        }

        $trustedHost = $this->host($this->urlParts($url));
        $allowConfiguredHost = static function (bool $external, string $host, string $requestUrl) use ($trustedHost): bool {
            if ($external) {
                return true;
            }

            $host = strtolower(trim($host, '[]'));

            return $host !== '' && hash_equals($trustedHost, $host);
        };

        add_filter('http_request_host_is_external', $allowConfiguredHost, 10, 3);
        try {
            return $request();
        } finally {
            remove_filter('http_request_host_is_external', $allowConfiguredHost, 10);
        }
    }

    /** @return array<string,mixed> */
    private function urlParts(string $url): array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw new TransportFailure('authority_url_invalid');
        }

        return $parts;
    }

    /** @param array<string,mixed> $parts */
    private function host(array $parts): string
    {
        return strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
    }
}
