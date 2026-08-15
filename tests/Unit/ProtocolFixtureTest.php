<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Tests\Unit;

use DateTimeImmutable;
use Zithis\LicenceClient\Enum\Operation;
use Zithis\LicenceClient\Protocol\Json;
use Zithis\LicenceClient\Protocol\ResponseDecoder;
use Zithis\LicenceClient\Security\SignatureVerifier;
use Zithis\LicenceClient\State\StateInterpreter;
use Zithis\LicenceClient\Tests\Support\TestCase;
use Zithis\LicenceClient\Value\Authority;
use Zithis\LicenceClient\Value\Installation;
use Zithis\LicenceClient\Value\Product;
use Zithis\LicenceClient\Value\TransportRequest;
use Zithis\LicenceClient\Value\TransportResponse;

final class ProtocolFixtureTest extends TestCase
{
    public function testSignedStateAndUpdateFixturesDecodeDeterministically(): void
    {
        $root = dirname(__DIR__, 2) . '/protocol/fixtures/v1';
        $manifest = Json::decode((string) file_get_contents($root . '/cases.json'));
        $authorityConfig = $manifest['authority'];
        $authority = new Authority(
            (string) $authorityConfig['id'],
            [(string) $authorityConfig['key_id'] => (string) file_get_contents($root . '/' . $authorityConfig['public_key'])]
        );
        $productConfig = $manifest['product'];
        $product = new Product((string) $productConfig['code'], (string) $productConfig['package_identifier'], (string) $productConfig['installed_version']);
        $installationConfig = $manifest['installation'];
        $installation = new Installation(
            (string) $installationConfig['id'],
            (string) $installationConfig['scope'],
            (string) $installationConfig['environment'],
            (string) $installationConfig['canonical_uri']
        );
        $decoder = new ResponseDecoder(new SignatureVerifier(), new StateInterpreter());
        $now = new DateTimeImmutable((string) $manifest['clock']);

        foreach ($manifest['cases'] as $case) {
            $operation = Operation::from((string) $case['operation']);
            $requestBody = '{}';
            if ($operation === Operation::PackageAuthorisation) {
                $requestBody = (string) file_get_contents($root . '/requests/package-authorisation.json');
            }
            $request = new TransportRequest(
                $operation,
                'https://licensing.zithis.example/protocol-test',
                [],
                $requestBody,
                (string) $case['request_id'],
                (string) $case['nonce']
            );
            $body = (string) file_get_contents($root . '/' . $case['response']);
            $result = $decoder->decode(
                $request,
                new TransportResponse(200, ['Content-Type' => 'application/json; charset=UTF-8'], $body),
                $product,
                $installation,
                $authority,
                $now
            );
            $this->assertSame((bool) $case['expected_success'], $result->successful(), 'Fixture failed: ' . $case['id']);
            if (isset($case['expected_status'])) {
                $this->assertNotNull($result->licence(), 'Missing licence in ' . $case['id']);
                $this->assertSame($case['expected_status'], $result->licence()?->status()->value, 'State mismatch in ' . $case['id']);
                $this->assertSame($case['expected_mode'], $result->licence()?->runtimeMode()->value, 'Mode mismatch in ' . $case['id']);
            }
            if (array_key_exists('expected_update', $case)) {
                $this->assertSame($case['expected_update'], $result->update()?->version(), 'Update mismatch in ' . $case['id']);
            }
            if (isset($case['expected_package'])) {
                $this->assertSame($case['expected_package'], $result->package()?->releaseId(), 'Package mismatch in ' . $case['id']);
            }
        }
    }

    public function testMalformedAndIncompatibleResponsesAreClassified(): void
    {
        [$decoder, $request, $product, $installation, $authority, $now] = $this->fixtureDecoder();
        $failure = Json::decode((string) file_get_contents(dirname(__DIR__, 2) . '/protocol/fixtures/v1/failures/malformed-response.json'));
        $malformed = $decoder->decode(
            $request,
            new TransportResponse((int) $failure['http_status'], $failure['headers'], (string) $failure['body']),
            $product,
            $installation,
            $authority,
            $now
        );
        $this->assertFalse($malformed->successful());
        $this->assertSame('malformed_response', $malformed->error()?->code());

        $errorBody = (string) file_get_contents(dirname(__DIR__, 2) . '/protocol/fixtures/v1/errors/incompatible.json');
        $incompatible = $decoder->decode(
            $request,
            new TransportResponse(409, ['Content-Type' => 'application/json'], $errorBody),
            $product,
            $installation,
            $authority,
            $now
        );
        $this->assertFalse($incompatible->successful());
        $this->assertSame('compatibility', $incompatible->error()?->category()->value);
        $this->assertSame('unsupported_protocol_version', $incompatible->error()?->code());
    }

    public function testTamperedSignedPayloadIsRejected(): void
    {
        [$decoder, $request, $product, $installation, $authority, $now] = $this->fixtureDecoder();
        $root = dirname(__DIR__, 2) . '/protocol/fixtures/v1';
        $response = Json::decode((string) file_get_contents($root . '/responses/active.json'));
        $response['signed_payload'] = substr((string) $response['signed_payload'], 0, -1) . 'A';
        $result = $decoder->decode(
            $request,
            new TransportResponse(200, ['Content-Type' => 'application/json'], Json::encode($response)),
            $product,
            $installation,
            $authority,
            $now
        );
        $this->assertFalse($result->successful());
        $this->assertSame('invalid_signature', $result->error()?->code());
    }


    public function testPackageAuthorisationIsBoundToTheRequestedReleaseAndChecksum(): void
    {
        $root = dirname(__DIR__, 2) . '/protocol/fixtures/v1';
        $manifest = Json::decode((string) file_get_contents($root . '/cases.json'));
        $case = null;
        foreach ($manifest['cases'] as $candidate) {
            if (($candidate['id'] ?? null) === 'package-authorisation') {
                $case = $candidate;
                break;
            }
        }
        $this->assertNotNull($case);
        $authority = new Authority(
            (string) $manifest['authority']['id'],
            [(string) $manifest['authority']['key_id'] => (string) file_get_contents($root . '/' . $manifest['authority']['public_key'])]
        );
        $requestPayload = Json::decode((string) file_get_contents($root . '/requests/package-authorisation.json'));
        $requestPayload['operation']['parameters']['checksum'] = str_repeat('b', 64);
        $request = new TransportRequest(
            Operation::PackageAuthorisation,
            'https://licensing.zithis.example/protocol-test',
            [],
            Json::encode($requestPayload),
            (string) $case['request_id'],
            (string) $case['nonce']
        );
        $result = (new ResponseDecoder(new SignatureVerifier(), new StateInterpreter()))->decode(
            $request,
            new TransportResponse(
                200,
                ['Content-Type' => 'application/json'],
                (string) file_get_contents($root . '/responses/package-authorisation.json')
            ),
            new Product('zithis', 'zithis/zithis.php', '1.1.27'),
            new Installation('11111111-1111-4111-8111-111111111111', 'customer.example', 'production', 'https://customer.example'),
            $authority,
            new DateTimeImmutable((string) $manifest['clock'])
        );
        $this->assertFalse($result->successful());
        $this->assertSame('package_release_mismatch', $result->error()?->code());
    }

    private function fixtureDecoder(): array
    {
        $root = dirname(__DIR__, 2) . '/protocol/fixtures/v1';
        $manifest = Json::decode((string) file_get_contents($root . '/cases.json'));
        $case = $manifest['cases'][0];
        $authority = new Authority(
            (string) $manifest['authority']['id'],
            [(string) $manifest['authority']['key_id'] => (string) file_get_contents($root . '/' . $manifest['authority']['public_key'])]
        );
        $product = new Product('zithis', 'zithis/zithis.php', '1.1.27');
        $installation = new Installation('11111111-1111-4111-8111-111111111111', 'customer.example', 'production', 'https://customer.example');
        $request = new TransportRequest(Operation::Activate, 'https://licensing.zithis.example/test', [], '{}', (string) $case['request_id'], (string) $case['nonce']);

        return [new ResponseDecoder(new SignatureVerifier(), new StateInterpreter()), $request, $product, $installation, $authority, new DateTimeImmutable((string) $manifest['clock'])];
    }
}
