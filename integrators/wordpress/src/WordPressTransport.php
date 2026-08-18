<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator;

use RuntimeException;
use Throwable;
use Zithis\LicenceClient\Contract\Transport;
use Zithis\LicenceClient\Exception\TransportFailure;
use Zithis\LicenceClient\Value\TransportRequest;
use Zithis\LicenceClient\Value\TransportResponse;
use Zithis\StandaloneWordPressIntegrator\Http\AuthorityHttpPolicy;

final class WordPressTransport implements Transport
{
    public function __construct(
        private int $timeout,
        private AuthorityHttpPolicy $http
    ) {
        $this->timeout = max(5, min($this->timeout, 30));
    }

    public function send(TransportRequest $request): TransportResponse
    {
        if (!function_exists('wp_remote_post')
            || !function_exists('is_wp_error')
            || !function_exists('wp_remote_retrieve_response_code')
            || !function_exists('wp_remote_retrieve_headers')
            || !function_exists('wp_remote_retrieve_body')) {
            throw new TransportFailure('wordpress_http_api_unavailable');
        }

        try {
            $response = $this->http->licenceRequest(
                $request->uri(),
                fn (): mixed => wp_remote_post($request->uri(), [
                    'timeout' => $this->timeout,
                    'redirection' => 0,
                    'reject_unsafe_urls' => true,
                    'sslverify' => true,
                    'headers' => $request->headers(),
                    'body' => $request->body(),
                    'data_format' => 'body',
                ])
            );
        } catch (TransportFailure $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new TransportFailure('transport_request_exception');
        }
        if (is_wp_error($response)) {
            throw new TransportFailure($this->failureCode($response));
        }

        $headers = wp_remote_retrieve_headers($response);
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            $headers = $headers->getAll();
        }
        if (!is_array($headers)) {
            $headers = [];
        }

        return new TransportResponse(
            (int) wp_remote_retrieve_response_code($response),
            $headers,
            (string) wp_remote_retrieve_body($response)
        );
    }

    private function failureCode(object $error): string
    {
        $code = method_exists($error, 'get_error_code')
            ? strtolower((string) $error->get_error_code())
            : '';
        $code = preg_replace('/[^a-z0-9._-]+/', '_', $code) ?: '';
        $code = trim($code, '._-');

        return $code !== ''
            ? 'transport_' . substr($code, 0, 60)
            : 'transport_wordpress_http_error';
    }
}
