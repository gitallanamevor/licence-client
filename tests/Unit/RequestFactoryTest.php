<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Tests\Unit;

use DateTimeImmutable;
use Zithis\LicenceClient\Enum\Operation;
use Zithis\LicenceClient\Protocol\Json;
use Zithis\LicenceClient\Protocol\RequestFactory;
use Zithis\LicenceClient\Tests\Support\TestCase;
use Zithis\LicenceClient\Value\ActivationCredential;
use Zithis\LicenceClient\Value\EndpointSet;
use Zithis\LicenceClient\Value\Installation;
use Zithis\LicenceClient\Value\Product;

final class RequestFactoryTest extends TestCase
{
    public function testRequestsSeparateProtocolClientProductInstallationCredentialAndOperation(): void
    {
        $factory = new RequestFactory();
        $endpoints = new EndpointSet([
            'activate' => 'https://licensing.zithis.example/v2/licences/activate',
            'validate' => 'https://licensing.zithis.example/v2/licences/validate',
            'deactivate' => 'https://licensing.zithis.example/v2/licences/deactivate',
            'update_check' => 'https://licensing.zithis.example/v2/updates/check',
            'package_authorisation' => 'https://licensing.zithis.example/v2/packages/authorise',
        ]);
        $request = $factory->create(
            Operation::Validate,
            $endpoints,
            new Product('zithis', 'zithis/zithis.php', '1.1.27'),
            new Installation('11111111-1111-4111-8111-111111111111', 'customer.example', 'production', 'https://customer.example'),
            new DateTimeImmutable('2026-08-02T14:00:00+00:00'),
            [],
            new ActivationCredential('33333333-3333-4333-8333-333333333333', str_repeat('S', 43))
        );
        $payload = Json::decode($request->body());
        $this->assertSame('1.0', $payload['protocol']['version']);
        $this->assertSame('zithis/licence-client', $payload['client']['name']);
        $this->assertSame('zithis', $payload['product']['code']);
        $this->assertSame('customer.example', $payload['installation']['scope']);
        $this->assertSame('validate', $payload['operation']['name']);
        $this->assertSame(str_repeat('S', 43), $payload['credential']['activation_secret']);
        $this->assertSame(
            '33333333-3333-4333-8333-333333333333',
            $request->headers()['X-Zithis-Activation'] ?? null
        );
        $this->assertFalse(
            in_array(str_repeat('S', 43), $request->headers(), true),
            'The activation secret must never be copied into transport headers.'
        );
        $this->assertFalse(isset($payload['site']), 'The product-neutral request must not contain a site object.');
        $this->assertFalse(isset($payload['plugin']), 'The product-neutral request must not contain a plugin object.');
    }
    public function testEmptyOperationParametersAreEncodedAsJsonObjects(): void
    {
        $factory = new RequestFactory();
        $endpoints = new EndpointSet([
            'activate' => 'https://licensing.zithis.example/v2/licences/activate',
            'validate' => 'https://licensing.zithis.example/v2/licences/validate',
            'deactivate' => 'https://licensing.zithis.example/v2/licences/deactivate',
            'update_check' => 'https://licensing.zithis.example/v2/updates/check',
            'package_authorisation' => 'https://licensing.zithis.example/v2/packages/authorise',
        ]);
        $product = new Product('zithis', 'zithis/zithis.php', '1.1.27');
        $installation = new Installation(
            '11111111-1111-4111-8111-111111111111',
            'customer.example',
            'production',
            'https://customer.example'
        );
        $credential = new ActivationCredential(
            '33333333-3333-4333-8333-333333333333',
            str_repeat('S', 43)
        );

        foreach ([Operation::Validate, Operation::Deactivate] as $operation) {
            $request = $factory->create(
                $operation,
                $endpoints,
                $product,
                $installation,
                new DateTimeImmutable('2026-08-02T14:00:00+00:00'),
                [],
                $credential
            );
            $decoded = json_decode($request->body(), false, 64, JSON_THROW_ON_ERROR);

            $this->assertTrue($decoded instanceof \stdClass);
            $this->assertTrue($decoded->operation instanceof \stdClass);
            $this->assertTrue(
                $decoded->operation->parameters instanceof \stdClass,
                $operation->value . ' parameters must be encoded as a JSON object.'
            );
            $this->assertContains('"parameters":{}', $request->body());
        }
    }

    public function testOperationParametersRejectJsonLists(): void
    {
        $factory = new RequestFactory();
        $endpoints = new EndpointSet([
            'activate' => 'https://licensing.zithis.example/v2/licences/activate',
            'validate' => 'https://licensing.zithis.example/v2/licences/validate',
            'deactivate' => 'https://licensing.zithis.example/v2/licences/deactivate',
            'update_check' => 'https://licensing.zithis.example/v2/updates/check',
            'package_authorisation' => 'https://licensing.zithis.example/v2/packages/authorise',
        ]);

        try {
            $factory->create(
                Operation::Validate,
                $endpoints,
                new Product('zithis', 'zithis/zithis.php', '1.1.27'),
                new Installation('11111111-1111-4111-8111-111111111111', 'customer.example', 'production'),
                new DateTimeImmutable('2026-08-02T14:00:00+00:00'),
                ['unexpected-list-entry'],
                new ActivationCredential('33333333-3333-4333-8333-333333333333', str_repeat('S', 43))
            );
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Operation parameters must be an associative array.', $exception->getMessage());

            return;
        }

        $this->assertTrue(false, 'A JSON-list operation parameter payload must be rejected.');
    }


    public function testActivationRequestDoesNotSendActivationIdentityHeader(): void
    {
        $factory = new RequestFactory();
        $request = $factory->create(
            Operation::Activate,
            new EndpointSet([
                'activate' => 'https://licensing.zithis.example/v2/licences/activate',
                'validate' => 'https://licensing.zithis.example/v2/licences/validate',
                'deactivate' => 'https://licensing.zithis.example/v2/licences/deactivate',
                'update_check' => 'https://licensing.zithis.example/v2/updates/check',
                'package_authorisation' => 'https://licensing.zithis.example/v2/packages/authorise',
            ]),
            new Product('zithis', 'zithis/zithis.php', '1.1.27'),
            new Installation('11111111-1111-4111-8111-111111111111', 'customer.example', 'production'),
            new DateTimeImmutable('2026-08-02T14:00:00+00:00'),
            ['licence_key' => 'ZITHIS-TEST-KEY']
        );

        $this->assertFalse(isset($request->headers()['X-Zithis-Activation']));
    }

}
