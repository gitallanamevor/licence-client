<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Tests\Unit;

use DateTimeImmutable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Zithis\LicenceClient\Security\PublicKeyPolicy;
use Zithis\LicenceClient\Security\Redactor;
use Zithis\LicenceClient\Tests\Support\TestCase;
use Zithis\LicenceClient\Enum\Operation;
use Zithis\LicenceClient\Value\EndpointSet;
use Zithis\LicenceClient\Value\Installation;
use Zithis\LicenceClient\Value\PackageAuthorisation;

final class SecurityAndBoundaryTest extends TestCase
{
    public function testSensitiveContextIsRedactedRecursively(): void
    {
        $redacted = (new Redactor())->context([
            'licence_key' => 'secret-key',
            'nested' => ['activation_secret' => 'secret-activation', 'request_id' => 'request-1'],
            'package_token' => 'secret-token',
            'X-Api-Key' => 'secret-api-key',
            'download_url' => 'https://downloads.zithis.example/package.zip?token=secret-token&release=123',
            'diagnostic' => 'Authorization: Bearer secret-bearer-token',
        ]);
        $this->assertSame('[REDACTED]', $redacted['licence_key']);
        $this->assertSame('[REDACTED]', $redacted['nested']['activation_secret']);
        $this->assertSame('request-1', $redacted['nested']['request_id']);
        $this->assertSame('[REDACTED]', $redacted['package_token']);
        $this->assertSame('[REDACTED]', $redacted['X-Api-Key']);
        $this->assertSame(
            'https://downloads.zithis.example/package.zip?token=[REDACTED]&release=123',
            $redacted['download_url']
        );
        $this->assertSame('Authorization: Bearer [REDACTED]', $redacted['diagnostic']);
    }


    public function testRs256PolicyRequiresStrongRsaPublicKey(): void
    {
        $fixture = (string) file_get_contents(dirname(__DIR__, 2) . '/protocol/fixtures/v1/authority/test-authority-public.pem');
        $this->assertTrue(PublicKeyPolicy::acceptsRs256($fixture));

        $this->assertFalse(
            PublicKeyPolicy::acceptsRs256(\Zithis\LicenceClient\Tests\Support\TestSigningKey::weakPublicKeyPem())
        );
    }

    public function testPackageAuthorisationRejectsCredentialBearingUri(): void
    {
        $rejected = false;
        try {
            new PackageAuthorisation(
                'release-123',
                'https://user:password@downloads.zithis.example/package.zip',
                str_repeat('T', 43),
                new DateTimeImmutable('+5 minutes'),
                str_repeat('a', 64)
            );
        } catch (\InvalidArgumentException) {
            $rejected = true;
        }
        $this->assertTrue($rejected, 'Credential-bearing package URLs must be rejected.');
    }

    public function testEndpointSetRejectsAnyCredentialOrFragmentComponent(): void
    {
        $base = [];
        foreach (Operation::cases() as $operation) {
            $base[$operation->value] = 'https://licensing.zithis.example/v1/' . $operation->value;
        }

        foreach ([
            'https://user@licensing.zithis.example/v1/activate',
            'https://user:password@licensing.zithis.example/v1/activate',
            'https://licensing.zithis.example/v1/activate#fragment',
        ] as $invalid) {
            $endpoints = $base;
            $endpoints[Operation::Activate->value] = $invalid;
            $rejected = false;
            try {
                new EndpointSet($endpoints);
            } catch (\InvalidArgumentException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'Unsafe endpoint URI components must be rejected.');
        }
    }

    public function testInstallationCanonicalUriRejectsCredentialsQueryAndFragment(): void
    {
        foreach ([
            'https://user@customer.example',
            'https://customer.example?token=secret',
            'https://customer.example#fragment',
        ] as $invalid) {
            $rejected = false;
            try {
                new Installation(
                    '123e4567-e89b-12d3-a456-426614174000',
                    'customer.example',
                    'production',
                    $invalid
                );
            } catch (\InvalidArgumentException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'Unsafe canonical installation URI components must be rejected.');
        }
    }

    public function testClientSourceHasNoFrameworkOrRuntimeIntegrationDependency(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $forbidden = [
            'Illuminate\\',
            'Laravel\\',
            'Zithis\\Settings\\',
            'Zithis\\Lib\\',
            'Zithis\\Licence\\',
            'wp_remote_',
            'wp_schedule_',
            'update_plugins',
            '$wpdb',
        ];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach ($forbidden as $token) {
                $this->assertFalse(str_contains($source, $token), $file->getFilename() . ' contains forbidden dependency ' . $token);
            }
        }
    }

    public function testProtocolSchemasDoNotUseLegacySiteOrPluginObjects(): void
    {
        $root = dirname(__DIR__, 2) . '/protocol/schema/v1';
        foreach (glob($root . '/*.json') ?: [] as $path) {
            $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            $keys = [];
            $walk = function (mixed $value) use (&$walk, &$keys): void {
                if (!is_array($value)) {
                    return;
                }
                foreach ($value as $key => $item) {
                    if (is_string($key)) {
                        $keys[] = $key;
                    }
                    $walk($item);
                }
            };
            $walk($decoded);
            $this->assertFalse(in_array('site', $keys, true), basename($path) . ' contains legacy site key.');
            $this->assertFalse(in_array('plugin', $keys, true), basename($path) . ' contains legacy plugin key.');
        }
    }
}
