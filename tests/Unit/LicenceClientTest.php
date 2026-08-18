<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Tests\Unit;

use DateTimeImmutable;
use Zithis\LicenceClient\LicenceClient;
use Zithis\LicenceClient\Protocol\RequestFactory;
use Zithis\LicenceClient\Protocol\ResponseDecoder;
use Zithis\LicenceClient\Security\Redactor;
use Zithis\LicenceClient\Security\SignatureVerifier;
use Zithis\LicenceClient\State\StateInterpreter;
use Zithis\LicenceClient\Tests\Support\ArrayCredentialStore;
use Zithis\LicenceClient\Tests\Support\DeactivationResponseLossTransport;
use Zithis\LicenceClient\Tests\Support\FixtureResponder;
use Zithis\LicenceClient\Tests\Support\FixedClock;
use Zithis\LicenceClient\Tests\Support\MemoryLogger;
use Zithis\LicenceClient\Tests\Support\StaticInstallationIdentity;
use Zithis\LicenceClient\Tests\Support\StaticProductDescriptor;
use Zithis\LicenceClient\Tests\Support\TestCase;
use Zithis\LicenceClient\Tests\Support\ThrowingTransport;
use Zithis\LicenceClient\Tests\Support\TimeoutTransport;
use Zithis\LicenceClient\Value\EndpointSet;
use Zithis\LicenceClient\Value\Installation;

final class LicenceClientTest extends TestCase
{
    public function testExplicitLifecycleUsesInjectedRuntimeContracts(): void
    {
        [$transport, $authority] = FixtureResponder::create();
        $store = new ArrayCredentialStore();
        $logger = new MemoryLogger();
        $client = $this->client($transport, $authority, $store, $logger);

        $activation = $client->activate('ZITHIS-TEST-KEY-0001');
        $this->assertTrue($activation->successful());
        $this->assertNotNull($store->load('zithis'));

        $validation = $client->validate();
        $this->assertTrue($validation->successful());
        $this->assertSame('active', $validation->licence()?->status()->value);

        $update = $client->checkForUpdate();
        $this->assertTrue($update->successful());
        $this->assertSame('1.1.28', $update->update()?->version());

        $package = $client->authorizePackage($update->update());
        $this->assertTrue($package->successful());
        $this->assertSame('release-zithis-1.1.28', $package->package()?->releaseId());

        $deactivation = $client->deactivate();
        $this->assertTrue($deactivation->successful());
        $this->assertSame(null, $store->load('zithis'));
        $this->assertTrue(count($logger->records) >= 10);
    }

    public function testDeactivationResponseLossIsReconciledWhenValidationConfirmsInvalidActivation(): void
    {
        [$delegate, $authority] = FixtureResponder::create();
        $transport = new DeactivationResponseLossTransport($delegate, true);
        $store = new ArrayCredentialStore();
        $logger = new MemoryLogger();
        $client = $this->client($transport, $authority, $store, $logger);

        $this->assertTrue($client->activate('ZITHIS-TEST-KEY-0001')->successful());
        $this->assertNotNull($store->load('zithis'));

        $result = $client->deactivate();

        $this->assertTrue($result->successful());
        $this->assertSame(null, $store->load('zithis'));
        $this->assertSame(['activate', 'deactivate', 'validate'], $transport->operations);
        $this->assertContains('licence_deactivation_reconciled', (string) json_encode($logger->records));
    }

    public function testDeactivationResponseLossAcceptsInstallationNotActiveConfirmation(): void
    {
        [$delegate, $authority] = FixtureResponder::create();
        $transport = new DeactivationResponseLossTransport(
            $delegate,
            true,
            'installation_not_active',
            'server'
        );
        $store = new ArrayCredentialStore();
        $client = $this->client($transport, $authority, $store, new MemoryLogger());

        $this->assertTrue($client->activate('ZITHIS-TEST-KEY-0001')->successful());
        $result = $client->deactivate();

        $this->assertTrue($result->successful());
        $this->assertSame(null, $store->load('zithis'));
    }

    public function testDeactivationResponseLossPreservesStateWhenValidationStillSucceeds(): void
    {
        [$delegate, $authority] = FixtureResponder::create();
        $transport = new DeactivationResponseLossTransport($delegate, false);
        $store = new ArrayCredentialStore();
        $logger = new MemoryLogger();
        $client = $this->client($transport, $authority, $store, $logger);

        $this->assertTrue($client->activate('ZITHIS-TEST-KEY-0001')->successful());
        $result = $client->deactivate();

        $this->assertFalse($result->successful());
        $this->assertSame('transport_failure', $result->error()?->code());
        $this->assertNotNull($store->load('zithis'));
        $this->assertSame(['activate', 'deactivate', 'validate'], $transport->operations);
        $this->assertFalse(str_contains((string) json_encode($logger->records), 'licence_deactivation_reconciled'));
    }

    public function testTypedTransportTimeoutIsPreservedAsRetryableFailure(): void
    {
        [, $authority] = FixtureResponder::create();
        $logger = new MemoryLogger();
        $client = $this->client(new TimeoutTransport(), $authority, new ArrayCredentialStore(), $logger);

        $result = $client->activate('SUPER-SECRET-LICENCE-KEY');

        $this->assertFalse($result->successful());
        $this->assertSame('transport_timeout', $result->error()?->code());
        $this->assertSame('transport', $result->error()?->category()->value);
        $this->assertTrue((bool) $result->error()?->retryable());
        $this->assertContains('transport_timeout', (string) json_encode($logger->records));
        $this->assertFalse(str_contains((string) json_encode($logger->records), 'SUPER-SECRET-LICENCE-KEY'));
    }

    public function testTransportFailureIsRetryableAndDoesNotLeakSecrets(): void
    {
        [, $authority] = FixtureResponder::create();
        $logger = new MemoryLogger();
        $client = $this->client(new ThrowingTransport(), $authority, new ArrayCredentialStore(), $logger);
        $result = $client->activate('SUPER-SECRET-LICENCE-KEY');
        $this->assertFalse($result->successful());
        $this->assertSame('transport', $result->error()?->category()->value);
        $this->assertTrue((bool) $result->error()?->retryable());
        $encoded = json_encode($logger->records);
        $this->assertFalse(str_contains((string) $encoded, 'SUPER-SECRET-LICENCE-KEY'));
    }


    public function testUnchangedAuthenticatedStateDoesNotForceAnotherEncryptedStateSave(): void
    {
        [$transport, $authority] = FixtureResponder::create();
        $store = new ArrayCredentialStore();
        $client = $this->client($transport, $authority, $store, new MemoryLogger());

        $this->assertTrue($client->activate('ZITHIS-TEST-KEY-0001')->successful());
        $this->assertSame(1, $store->saveCount);
        $this->assertSame(1, $store->validationContactCount);

        $result = $client->checkForUpdate();

        $this->assertTrue($result->successful());
        $this->assertSame(1, $store->saveCount, 'An unchanged update-check licence state must not be persisted again.');
        $this->assertSame(1, $store->validationContactCount, 'Update checks must not refresh explicit validation-contact metadata.');

        $this->assertTrue($client->validate()->successful());
        $this->assertSame(2, $store->validationContactCount, 'Explicit validation must refresh validation-contact metadata.');
    }

    private function client($transport, $authority, ArrayCredentialStore $store, MemoryLogger $logger): LicenceClient
    {
        return new LicenceClient(
            $transport,
            $store,
            new StaticInstallationIdentity(new Installation(
                '11111111-1111-4111-8111-111111111111',
                'customer.example',
                'production',
                'https://customer.example'
            )),
            new FixedClock(new DateTimeImmutable('2026-08-02T14:00:00+00:00')),
            $logger,
            new StaticProductDescriptor('zithis', 'zithis/zithis.php', '1.1.27'),
            new EndpointSet([
                'activate' => 'https://licensing.zithis.example/v2/licences/activate',
                'validate' => 'https://licensing.zithis.example/v2/licences/validate',
                'deactivate' => 'https://licensing.zithis.example/v2/licences/deactivate',
                'update_check' => 'https://licensing.zithis.example/v2/updates/check',
                'package_authorisation' => 'https://licensing.zithis.example/v2/packages/authorise',
            ]),
            $authority,
            new RequestFactory(),
            new ResponseDecoder(new SignatureVerifier(), new StateInterpreter()),
            new Redactor()
        );
    }
}
