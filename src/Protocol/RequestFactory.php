<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Protocol;

use DateTimeImmutable;
use Zithis\LicenceClient\ClientPackage;
use Zithis\LicenceClient\Enum\Operation;
use Zithis\LicenceClient\Value\ActivationCredential;
use Zithis\LicenceClient\Value\EndpointSet;
use Zithis\LicenceClient\Value\Installation;
use Zithis\LicenceClient\Value\Product;
use Zithis\LicenceClient\Value\TransportRequest;

final class RequestFactory
{
    /** @param array<string,mixed> $parameters */
    public function create(
        Operation $operation,
        EndpointSet $endpoints,
        Product $product,
        Installation $installation,
        DateTimeImmutable $now,
        array $parameters = [],
        ?ActivationCredential $credential = null
    ): TransportRequest {
        $requestId = $this->uuid();
        $nonce = Base64Url::encode(random_bytes(24));
        $payload = [
            'protocol' => [
                'version' => ClientPackage::PROTOCOL_VERSION,
                'request_id' => $requestId,
                'timestamp' => $now->format(DATE_ATOM),
                'nonce' => $nonce,
            ],
            'client' => [
                'name' => ClientPackage::NAME,
                'version' => ClientPackage::VERSION,
                'supported_protocol_versions' => ClientPackage::supportedProtocolVersions(),
            ],
            'product' => $product->toArray(),
            'installation' => $installation->toArray(),
            'operation' => [
                'name' => $operation->value,
                'parameters' => $this->operationParameters($parameters),
            ],
        ];
        if ($credential !== null) {
            $payload['credential'] = $credential->toArray();
        }

        return new TransportRequest(
            $operation,
            $endpoints->for($operation),
            [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => ClientPackage::NAME . '/' . ClientPackage::VERSION,
            ],
            Json::encode($payload),
            $requestId,
            $nonce
        );
    }

    /** @param array<string,mixed> $parameters @return array<string,mixed>|\stdClass */
    private function operationParameters(array $parameters): array|\stdClass
    {
        if ($parameters === []) {
            return new \stdClass();
        }
        if (array_is_list($parameters)) {
            throw new \InvalidArgumentException('Operation parameters must be an associative array.');
        }

        return $parameters;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
