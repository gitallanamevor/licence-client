<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Tests\Unit;

use DateTimeImmutable;
use RuntimeException;
use Zithis\LicenceClient\Contract\Clock;
use Zithis\LicenceClient\Integrator\Php\ApplicationRuntime;
use Zithis\LicenceClient\Integrator\Php\Configuration;
use Zithis\LicenceClient\Integrator\Php\RuntimeUnavailable;
use Zithis\LicenceClient\Integrator\Php\Update\PackageTransport;
use Zithis\LicenceClient\Runtime\Status;
use Zithis\LicenceClient\Tests\Support\FixtureResponder;
use Zithis\LicenceClient\Tests\Support\TestCase;

final class PhpApplicationIntegratorTest extends TestCase
{
    public function test_unconfigured_application_runtime_is_blocked_without_network_activity(): void
    {
        [$transport, $authority] = FixtureResponder::create();
        $root = $this->temporary('blocked');
        $runtime = ApplicationRuntime::create(
            new Configuration($this->configuration($root, (string) $authority->publicKey('runtime-fixture'))),
            null,
            $transport,
            new MemoryPackageTransport('unused'),
            new AdjustableClock(new DateTimeImmutable('2026-08-04T12:00:00+00:00'))
        );

        $booted = false;
        $this->assertFalse($runtime->boot(static function () use (&$booted): void { $booted = true; }));
        $this->assertFalse($booted);
        $this->assertSame(Status::UNCONFIGURED, $runtime->status()->code());
        $failed = false;
        try {
            $runtime->assertBusinessRuntime();
        } catch (RuntimeUnavailable) {
            $failed = true;
        }
        $this->assertTrue($failed, 'The unlicensed application runtime was not blocked.');
        $this->delete($root);
    }

    public function test_activation_persists_encrypted_state_and_admits_the_business_runtime(): void
    {
        [$transport, $authority] = FixtureResponder::create();
        $root = $this->temporary('activation');
        $configuration = new Configuration($this->configuration($root, (string) $authority->publicKey('runtime-fixture')));
        $clock = new AdjustableClock(new DateTimeImmutable('2026-08-04T12:00:00+00:00'));
        $runtime = ApplicationRuntime::create($configuration, null, $transport, new MemoryPackageTransport('unused'), $clock);

        $result = $runtime->licences()->activate('PORTABLE-APP-LICENCE-KEY');
        $this->assertTrue($result->successful(), 'The PHP application licence activation failed.');
        $this->assertSame(Status::ACTIVE, $runtime->status()->code());
        $booted = false;
        $this->assertTrue($runtime->boot(static function () use (&$booted): void { $booted = true; }));
        $this->assertTrue($booted, 'The licensed PHP application runtime was not admitted.');

        $stateRoot = $root . '/' . $configuration->stateNamespace();
        $sealed = (string) file_get_contents($stateRoot . '/credential.bin');
        $this->assertFalse(str_contains($sealed, str_repeat('S', 43)), 'The activation secret was stored in plaintext.');
        $this->assertFalse(str_contains($sealed, 'licence-runtime-0001'), 'The licence identifier was stored in plaintext.');
        $identity = (string) file_get_contents($stateRoot . '/installation.json');

        $second = ApplicationRuntime::create($configuration, null, $transport, new MemoryPackageTransport('unused'), $clock);
        $this->assertTrue($second->boot(static function (): void {}), 'A second application process did not reuse the encrypted licence state.');
        $this->assertSame($identity, (string) file_get_contents($stateRoot . '/installation.json'));
        $this->delete($root);
    }

    public function test_explicit_maintenance_runner_owns_due_validation_and_update_discovery(): void
    {
        [$transport, $authority] = FixtureResponder::create();
        $root = $this->temporary('maintenance');
        $clock = new AdjustableClock(new DateTimeImmutable('2026-08-04T12:00:00+00:00'));
        $runtime = ApplicationRuntime::create(
            new Configuration($this->configuration($root, (string) $authority->publicKey('runtime-fixture'))),
            null,
            $transport,
            new MemoryPackageTransport('unused'),
            $clock
        );
        $this->assertTrue($runtime->licences()->activate('PORTABLE-APP-LICENCE-KEY')->successful());
        $clock->set(new DateTimeImmutable('2026-08-06T12:00:00+00:00'));

        $report = $runtime->maintenance()->run();
        $this->assertTrue($report->lockAcquired());
        $this->assertTrue($report->validationAttempted());
        $this->assertTrue($report->validationSuccessful() === true);
        $this->assertTrue($report->updateAttempted());
        $this->assertTrue($report->updateSuccessful() === true);
        $this->assertNotNull($runtime->updates()->offer());

        $second = $runtime->maintenance()->run();
        $this->assertFalse($second->validationAttempted(), 'Maintenance repeated validation before the refreshed due time.');
        $this->delete($root);
    }

    public function test_authorised_update_is_staged_without_deploying_application_files(): void
    {
        $package = 'portable application release package';
        $checksum = hash('sha256', $package);
        [$transport, $authority] = FixtureResponder::create($checksum);
        $root = $this->temporary('update');
        $clock = new AdjustableClock(new DateTimeImmutable('2026-08-04T12:00:00+00:00'));
        $packageTransport = new MemoryPackageTransport($package);
        $runtime = ApplicationRuntime::create(
            new Configuration($this->configuration($root, (string) $authority->publicKey('runtime-fixture'))),
            null,
            $transport,
            $packageTransport,
            $clock
        );
        $this->assertTrue($runtime->licences()->activate('PORTABLE-APP-LICENCE-KEY')->successful());
        $this->assertTrue($runtime->updates()->discover(true));
        $offer = $runtime->updates()->offer();
        $this->assertNotNull($offer);

        $staged = $runtime->updates()->stage($offer, $root . '/packages');
        $this->assertTrue(is_file($staged->path()));
        $this->assertSame($checksum, $staged->checksum());
        $this->assertSame($checksum, (string) hash_file('sha256', $staged->path()));
        $this->assertContains('token=', $packageTransport->uri);
        $this->assertFalse(is_dir($root . '/deployed'), 'The generic integrator deployed application files unexpectedly.');
        $this->delete($root);
    }

    public function test_configuration_rejects_insecure_production_endpoints_and_relative_state(): void
    {
        [, $authority] = FixtureResponder::create();
        $payload = $this->configuration('/tmp/zlc-state', (string) $authority->publicKey('runtime-fixture'));
        $payload['installation']['environment'] = 'production';
        $payload['authority']['endpoints']['activate'] = 'http://licence.example.test/v1/licences/activate';
        $failed = false;
        try {
            new Configuration($payload);
        } catch (\InvalidArgumentException) {
            $failed = true;
        }
        $this->assertTrue($failed, 'An insecure production endpoint was accepted.');

        $payload = $this->configuration('relative/state', (string) $authority->publicKey('runtime-fixture'));
        $failed = false;
        try {
            new Configuration($payload);
        } catch (\InvalidArgumentException) {
            $failed = true;
        }
        $this->assertTrue($failed, 'A relative state directory was accepted.');
    }

    /** @return array<string,mixed> */
    private function configuration(string $stateDirectory, string $publicKey): array
    {
        return [
            'contract_version' => 1,
            'product' => [
                'code' => 'portable-app',
                'name' => 'Portable App',
                'package_identifier' => 'vendor/portable-app',
                'installed_version' => '1.0.0',
            ],
            'installation' => [
                'scope' => 'portable-app/main',
                'environment' => 'local',
                'canonical_uri' => 'urn:portable-app:main',
            ],
            'authority' => [
                'id' => 'licensing.zithis.example',
                'public_keys' => ['runtime-fixture' => $publicKey],
                'package_download_hosts' => ['licensing.zithis.example'],
                'endpoints' => [
                    'activate' => 'http://licence.example.test/v1/licences/activate',
                    'validate' => 'http://licence.example.test/v1/licences/validate',
                    'deactivate' => 'http://licence.example.test/v1/licences/deactivate',
                    'update_check' => 'http://licence.example.test/v1/updates/check',
                    'package_authorisation' => 'http://licence.example.test/v1/packages/authorize',
                ],
            ],
            'runtime' => [
                'state_directory' => $stateDirectory,
                'timeout_seconds' => 30,
                'validation_retry_seconds' => 300,
                'update_check_seconds' => 3600,
                'lock_wait_seconds' => 0,
                'maximum_response_bytes' => 2097152,
                'maximum_package_bytes' => 10485760,
            ],
        ];
    }

    private function temporary(string $name): string
    {
        $path = sys_get_temp_dir() . '/zlc-php-' . $name . '-' . bin2hex(random_bytes(5));
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('The test state directory could not be created.');
        }

        return $path;
    }

    private function delete(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}

final class AdjustableClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable { return $this->now; }
    public function set(DateTimeImmutable $now): void { $this->now = $now; }
}

final class MemoryPackageTransport implements PackageTransport
{
    public string $uri = '';

    public function __construct(private string $content)
    {
    }

    public function download(string $uri, string $destination, int $timeoutSeconds, int $maximumBytes): void
    {
        $this->uri = $uri;
        if (strlen($this->content) > $maximumBytes) {
            throw new RuntimeException('Package too large.');
        }
        if (file_put_contents($destination, $this->content, LOCK_EX) !== strlen($this->content)) {
            throw new RuntimeException('Test package could not be written.');
        }
    }
}
