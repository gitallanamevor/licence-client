<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Tests\Unit;

use DateTimeImmutable;
use RuntimeException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Zithis\LicenceClient\Build\ComposerAutoloadGenerator;
use Zithis\LicenceClient\Build\ComposerCommandAutoloadGenerator;
use Zithis\LicenceClient\Build\WordPressIntegratorBuilder;
use Zithis\LicenceClient\Build\WordPressIntegratorManifest;
use Zithis\LicenceClient\Tests\Support\TestCase;

final class WordPressIntegratorBuildTest extends TestCase
{
    public function testGeneratorProducesDeterministicIsolatedBuilds(): void
    {
        $root = $this->temporary('deterministic');
        $manifest = $this->manifest($root, 'alpha-plugin', 'alpha-plugin/alpha-plugin.php', 'Acme\\Alpha\\Internal\\Licence');
        $first = $root . '/first';
        $second = $root . '/second';
        $manifestValue = WordPressIntegratorManifest::fromFile($manifest);
        $builder = new WordPressIntegratorBuilder(new ComposerCommandAutoloadGenerator());
        $builder->build($manifestValue, $first);
        $builder->build($manifestValue, $second);

        $this->assertSame($this->hashes($first), $this->hashes($second));
        $firstComposer = json_decode((string) file_get_contents($first . '/composer.json'), true, 16, JSON_THROW_ON_ERROR);
        $secondComposer = json_decode((string) file_get_contents($second . '/composer.json'), true, 16, JSON_THROW_ON_ERROR);
        $expectedSuffix = 'ZLC' . strtoupper(substr(hash('sha256', $manifestValue->namespacePrefix()), 0, 20));
        $this->assertSame($expectedSuffix, (string) ($firstComposer['config']['autoloader-suffix'] ?? ''));
        $this->assertSame($expectedSuffix, (string) ($secondComposer['config']['autoloader-suffix'] ?? ''));
        $this->assertTrue(is_file($first . '/integration-manifest.json'));
        $this->assertTrue(is_file($first . '/LICENSE'), 'Generated standalone runtimes must carry the MIT licence notice.');
        $this->assertSame('MIT', (string) ($firstComposer['license'] ?? ''));
        $this->assertFalse(str_contains($this->sources($first), 'Zithis\\LicenceClient'));
        $this->assertFalse(str_contains($this->sources($first), 'Zithis\\StandaloneWordPressIntegrator'));
        $this->delete($root);
    }

    public function testIndependentProductsLoadWithoutNamespaceCollision(): void
    {
        $root = $this->temporary('isolation');
        $builder = new WordPressIntegratorBuilder(new ComposerCommandAutoloadGenerator());
        $one = $root . '/one';
        $two = $root . '/two';
        $builder->build(WordPressIntegratorManifest::fromFile(
            $this->manifest($root . '/m1', 'one-plugin', 'one-plugin/one-plugin.php', 'Acme\\Shared\\Internal\\Licence')
        ), $one);
        $builder->build(WordPressIntegratorManifest::fromFile(
            $this->manifest($root . '/m2', 'two-plugin', 'two-plugin/two-plugin.php', 'Acme\\Shared\\Internal\\Licence')
        ), $two);

        require $one . '/vendor/autoload.php';
        require $two . '/vendor/autoload.php';
        $oneNamespace = $this->finalNamespace('Acme\\Shared\\Internal\\Licence', 'one-plugin', 'one-plugin/one-plugin.php');
        $twoNamespace = $this->finalNamespace('Acme\\Shared\\Internal\\Licence', 'two-plugin', 'two-plugin/two-plugin.php');
        $this->assertTrue(class_exists($oneNamespace . '\\Client\\LicenceClient'));
        $this->assertTrue(class_exists($twoNamespace . '\\Client\\LicenceClient'));
        $this->assertTrue(class_exists($oneNamespace . '\\WordPress\\Plugin'));
        $this->assertTrue(class_exists($twoNamespace . '\\WordPress\\Plugin'));
        $this->delete($root);
    }

    public function testEncryptedStateAdmitsOnlyLicensedBusinessRuntime(): void
    {
        $root = $this->temporary('runtime');
        $plugins = $root . '/plugins';
        $GLOBALS['zithis_test_plugin_root'] = $plugins;
        $GLOBALS['zithis_test_options'] = [];
        $GLOBALS['zithis_test_hooks'] = [];
        $builder = new WordPressIntegratorBuilder(new ComposerCommandAutoloadGenerator());

        $licensedOutput = $root . '/licensed-build';
        $builder->build(WordPressIntegratorManifest::fromFile(
            $this->manifest($root . '/licensed-manifest', 'licensed-plugin', 'licensed-plugin/licensed-plugin.php', 'Acme\\Licensed\\Internal\\Licence')
        ), $licensedOutput);
        require $licensedOutput . '/vendor/autoload.php';
        $licensedFile = $this->pluginFile($plugins, 'licensed-plugin', 'licensed-plugin.php', '1.2.3');
        $licensedNamespace = $this->finalNamespace('Acme\\Licensed\\Internal\\Licence', 'licensed-plugin', 'licensed-plugin/licensed-plugin.php');
        $this->seedState($licensedNamespace, $licensedFile);

        $booted = false;
        $pluginClass = $licensedNamespace . '\\WordPress\\Plugin';
        $runtime = $pluginClass::register($licensedFile, static function () use (&$booted): void { $booted = true; });
        do_action('plugins_loaded');
        $this->assertTrue($booted, 'A licensed standalone plugin business runtime was not admitted.');
        $this->assertTrue($runtime->businessRuntimeBooted());

        $blockedOutput = $root . '/blocked-build';
        $builder->build(WordPressIntegratorManifest::fromFile(
            $this->manifest($root . '/blocked-manifest', 'blocked-plugin', 'blocked-plugin/blocked-plugin.php', 'Acme\\Blocked\\Internal\\Licence')
        ), $blockedOutput);
        require $blockedOutput . '/vendor/autoload.php';
        $blockedFile = $this->pluginFile($plugins, 'blocked-plugin', 'blocked-plugin.php', '1.0.0');
        $blocked = false;
        $blockedNamespace = $this->finalNamespace('Acme\\Blocked\\Internal\\Licence', 'blocked-plugin', 'blocked-plugin/blocked-plugin.php');
        $blockedClass = $blockedNamespace . '\\WordPress\\Plugin';
        $blockedRuntime = $blockedClass::register($blockedFile, static function () use (&$blocked): void { $blocked = true; });
        do_action('plugins_loaded');
        $this->assertFalse($blocked, 'An unlicensed standalone plugin business runtime was admitted.');
        $this->assertFalse($blockedRuntime->businessRuntimeBooted());
        $this->delete($root);
    }

    public function testLicensedRuntimeBootsWhenRegisteredAfterPluginsLoaded(): void
    {
        $root = $this->temporary('late-runtime-registration');
        $plugins = $root . '/plugins';
        $GLOBALS['zithis_test_plugin_root'] = $plugins;
        $GLOBALS['zithis_test_options'] = [];
        $GLOBALS['zithis_test_hooks'] = [];
        $GLOBALS['zithis_test_action_counts'] = ['plugins_loaded' => 1];

        $output = $root . '/build';
        $manifest = WordPressIntegratorManifest::fromFile(
            $this->manifest($root . '/manifest', 'late-plugin', 'late-plugin/late-plugin.php', 'Acme\Late\Internal\Licence')
        );
        (new WordPressIntegratorBuilder(new ComposerCommandAutoloadGenerator()))->build($manifest, $output);
        require $output . '/vendor/autoload.php';

        $pluginFile = $this->pluginFile($plugins, 'late-plugin', 'late-plugin.php', '1.2.3');
        $namespace = $this->finalNamespace('Acme\Late\Internal\Licence', 'late-plugin', 'late-plugin/late-plugin.php');
        $this->seedState($namespace, $pluginFile);

        $bootCount = 0;
        $pluginClass = $namespace . '\WordPress\Plugin';
        $runtime = $pluginClass::register($pluginFile, static function () use (&$bootCount): void { $bootCount++; });

        $this->assertSame(1, $bootCount, 'A licensed runtime registered after plugins_loaded must boot immediately.');
        $this->assertTrue($runtime->businessRuntimeBooted());
        $runtime->bootBusinessRuntime();
        $this->assertSame(1, $bootCount, 'Business bootstrap must execute at most once per request.');
        $this->delete($root);
    }

    public function testLicenceStateChangeAdmitsBusinessRuntimeWithoutReRegistration(): void
    {
        $root = $this->temporary('state-change-runtime');
        $plugins = $root . '/plugins';
        $GLOBALS['zithis_test_plugin_root'] = $plugins;
        $GLOBALS['zithis_test_options'] = [];
        $GLOBALS['zithis_test_hooks'] = [];
        $GLOBALS['zithis_test_action_counts'] = [];

        $output = $root . '/build';
        $manifest = WordPressIntegratorManifest::fromFile(
            $this->manifest($root . '/manifest', 'state-plugin', 'state-plugin/state-plugin.php', 'Acme\State\Internal\Licence')
        );
        (new WordPressIntegratorBuilder(new ComposerCommandAutoloadGenerator()))->build($manifest, $output);
        require $output . '/vendor/autoload.php';

        $pluginFile = $this->pluginFile($plugins, 'state-plugin', 'state-plugin.php', '1.2.3');
        $namespace = $this->finalNamespace('Acme\State\Internal\Licence', 'state-plugin', 'state-plugin/state-plugin.php');
        $pluginClass = $namespace . '\WordPress\Plugin';
        $generatedClass = $namespace . '\WordPress\GeneratedConfig';
        $configurationClass = $namespace . '\WordPress\Configuration';
        $configuration = new $configurationClass($generatedClass::data());

        $bootCount = 0;
        $runtime = $pluginClass::register($pluginFile, static function () use (&$bootCount): void { $bootCount++; });
        do_action('plugins_loaded');
        $this->assertSame(0, $bootCount, 'An unlicensed business runtime must remain gated.');

        $this->seedState($namespace, $pluginFile);
        do_action($configuration->stateChangedHook(), $configuration->productCode(), null);
        $this->assertSame(1, $bootCount, 'A newly usable licence state must admit the business runtime in the same request.');
        do_action($configuration->stateChangedHook(), $configuration->productCode(), null);
        $this->assertSame(1, $bootCount, 'Repeated licence state changes must not boot business runtime twice.');
        $this->assertTrue($runtime->businessRuntimeBooted());
        $this->delete($root);
    }

    public function testFailedGenerationRemovesIncompleteOutput(): void
    {
        $root = $this->temporary('failed-generation-cleanup');
        $output = $root . '/failed-build';
        $manifest = $this->manifest(
            $root . '/manifest',
            'cleanup-plugin',
            'cleanup-plugin/cleanup-plugin.php',
            'Acme\Cleanup\Internal\Licence'
        );
        $autoload = new class implements ComposerAutoloadGenerator {
            public function generate(string $packageRoot): void
            {
                throw new RuntimeException('Intentional Composer failure.');
            }
        };

        $failed = false;
        try {
            (new WordPressIntegratorBuilder($autoload))->build(
                WordPressIntegratorManifest::fromFile($manifest),
                $output
            );
        } catch (RuntimeException $exception) {
            $failed = $exception->getMessage() === 'Intentional Composer failure.';
        }

        $this->assertTrue($failed, 'The generated runtime did not surface the original build failure.');
        $this->assertFalse(is_dir($output), 'A failed generated runtime must remove its incomplete output directory.');
        $this->delete($root);
    }

    public function testManifestAcceptsEmptyOptionalReleaseMetadata(): void
    {
        $root = $this->temporary('optional-release-metadata');
        $path = $this->manifest($root, 'optional-plugin', 'optional-plugin/optional-plugin.php', 'Acme\\OptionalPlugin\\Internal\\Licence');
        $payload = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $payload['product']['homepage'] = '';
        $payload['product']['tested_wordpress'] = '';
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $manifest = WordPressIntegratorManifest::fromFile($path);
        $this->assertSame('', $manifest->homepage());
        $this->assertSame('', $manifest->testedWordPress());
        $this->delete($root);
    }

    public function testManifestRejectsCoreProductIdentity(): void
    {
        $root = $this->temporary('invalid');
        $path = $this->manifest($root, 'valid-plugin', 'valid-plugin/valid-plugin.php', 'Acme\\Valid\\Internal\\Licence');
        $payload = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $payload['product']['code'] = 'zithis';
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $failed = false;
        try {
            WordPressIntegratorManifest::fromFile($path);
        } catch (\InvalidArgumentException) {
            $failed = true;
        }
        $this->assertTrue($failed, 'The standalone manifest accepted the Zithis core product identity.');
        $this->delete($root);
    }

    public function testPublicAuthorityRejectsPlainHttpEndpoints(): void
    {
        $root = $this->temporary('public-http-rejected');
        $path = $this->manifest($root, 'public-plugin', 'public-plugin/public-plugin.php', 'Acme\\PublicPlugin\\Internal\\Licence');
        $payload = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        foreach ($payload['authority']['endpoints'] as $operation => $endpoint) {
            $payload['authority']['endpoints'][$operation] = preg_replace('/^https:/', 'http:', (string) $endpoint);
        }
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $failed = false;
        try {
            WordPressIntegratorManifest::fromFile($path);
        } catch (\InvalidArgumentException) {
            $failed = true;
        }
        $this->assertTrue($failed, 'A public standalone authority accepted plain HTTP endpoints.');
        $this->delete($root);
    }

    public function testPrivateDevelopmentAuthorityIsExactHostScoped(): void
    {
        $root = $this->temporary('private-authority');
        $path = $this->manifest(
            $root . '/manifest',
            'private-plugin',
            'private-plugin/private-plugin.php',
            'Acme\\PrivatePlugin\\Internal\\Licence',
            true,
            'http://licensing.zithis.test'
        );
        $manifest = WordPressIntegratorManifest::fromFile($path);
        $this->assertTrue($manifest->allowsPrivateNetwork());

        $output = $root . '/build';
        (new WordPressIntegratorBuilder(new ComposerCommandAutoloadGenerator()))->build($manifest, $output);
        require $output . '/vendor/autoload.php';
        $namespace = $this->finalNamespace(
            'Acme\\PrivatePlugin\\Internal\\Licence',
            'private-plugin',
            'private-plugin/private-plugin.php'
        );
        $configurationClass = $namespace . '\\WordPress\\Configuration';
        $generatedClass = $namespace . '\\WordPress\\GeneratedConfig';
        $policyClass = $namespace . '\\WordPress\\Http\\AuthorityHttpPolicy';
        $configuration = new $configurationClass($generatedClass::data());
        $policy = new $policyClass($configuration);

        $allowed = $policy->licenceRequest(
            'http://licensing.zithis.test/v1/licences/activate',
            static fn (): bool => (bool) apply_filters(
                'http_request_host_is_external',
                false,
                'licensing.zithis.test',
                'http://licensing.zithis.test/v1/licences/activate'
            )
        );
        $this->assertTrue($allowed, 'The configured private authority host was not admitted for the bounded request.');
        $this->assertFalse((bool) apply_filters(
            'http_request_host_is_external',
            false,
            'licensing.zithis.test',
            'http://licensing.zithis.test/v1/licences/activate'
        ), 'The private authority host filter leaked beyond the bounded request.');

        $rejected = false;
        try {
            $policy->licenceRequest('http://other.test/v1/licences/activate', static fn (): bool => true);
        } catch (\Throwable) {
            $rejected = true;
        }
        $this->assertTrue($rejected, 'An unconfigured licence endpoint was admitted by the private authority policy.');

        $this->assertSame(true, $policy->packageRequest(
            'http://licensing.zithis.test/download/package.zip?token=test',
            static fn (): bool => true
        ));
        $this->delete($root);
    }

    private function seedState(string $prefix, string $pluginFile): void
    {
        $configurationClass = $prefix . '\\WordPress\\Configuration';
        $generatedClass = $prefix . '\\WordPress\\GeneratedConfig';
        $clockClass = $prefix . '\\WordPress\\SystemClock';
        $metadataClass = $prefix . '\\WordPress\\Storage\\MetadataStore';
        $keyClass = $prefix . '\\WordPress\\Storage\\SecretKeyStore';
        $storeClass = $prefix . '\\WordPress\\Storage\\WordPressCredentialStore';
        $credentialClass = $prefix . '\\Client\\Value\\ActivationCredential';
        $licenceClass = $prefix . '\\Client\\Value\\LicenceState';
        $storedClass = $prefix . '\\Client\\Value\\StoredState';
        $codecClass = $prefix . '\\Client\\Runtime\\LicenceStateCodec';
        $statusClass = $prefix . '\\Client\\Enum\\LicenceStatus';
        $modeClass = $prefix . '\\Client\\Enum\\RuntimeMode';

        $configuration = new $configurationClass($generatedClass::data());
        $clock = new $clockClass();
        $metadata = new $metadataClass($configuration);
        $store = new $storeClass($configuration, new $keyClass($configuration), $metadata, $clock, new $codecClass());
        $now = new DateTimeImmutable('now');
        $state = new $licenceClass(
            'licence-test-1',
            $statusClass::Active,
            ['suite', 'updates'],
            $now->modify('-1 day'),
            $now->modify('+1 year'),
            $now->modify('+1 day'),
            $now->modify('+14 days'),
            ['production' => 1, 'non_production' => 1],
            ['production' => 1, 'non_production' => 0],
            $modeClass::Full,
            false
        );
        $secret = str_repeat('A', 43);
        $stored = new $storedClass(
            new $credentialClass('123e4567-e89b-12d3-a456-426614174000', $secret),
            $state
        );
        $store->save($configuration->productCode(), $stored);
        $sealed = (string) $GLOBALS['zithis_test_options'][$configuration->stateOption()];
        $this->assertFalse(str_contains($sealed, $secret), 'The activation secret was stored in plaintext.');
        $seedOption = $configuration->optionPrefix() . '_key_seed';
        $this->assertTrue(isset($GLOBALS['zithis_test_options'][$seedOption]), 'The product-scoped encryption seed was not persisted.');
        $this->assertFalse(str_contains((string) $GLOBALS['zithis_test_options'][$seedOption], $secret), 'The encryption seed must not contain activation secret material.');
        $this->assertFalse(str_contains($sealed, 'licence-test-1'), 'The licence identifier was stored in plaintext.');
        $this->assertSame('1.2.3', $this->installedVersion($prefix, $configuration, $pluginFile));
    }

    private function installedVersion(string $prefix, object $configuration, string $pluginFile): string
    {
        $productClass = $prefix . '\\WordPress\\ProductDescriptor';

        return (new $productClass($configuration, $pluginFile))->installedVersion();
    }

    private function finalNamespace(string $base, string $code, string $package): string
    {
        return trim($base, ' \\') . '\\ZLC' . strtoupper(substr(hash('sha256', $code . '|' . $package), 0, 12));
    }

    private function manifest(
        string $directory,
        string $code,
        string $package,
        string $namespace,
        bool $allowPrivateNetwork = false,
        string $authorityBase = 'https://licence.example.com'
    ): string
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Test manifest directory could not be created.');
        }
        $payload = [
            'contract_version' => 2,
            'product' => [
                'code' => $code,
                'name' => ucwords(str_replace('-', ' ', $code)),
                'package_identifier' => $package,
                'homepage' => 'https://example.com/' . $code,
                'minimum_php' => '8.1',
                'minimum_wordpress' => '6.5',
                'tested_wordpress' => '6.8',
            ],
            'namespace' => $namespace,
            'authority' => [
                'id' => 'zithis-production',
                'key_id' => 'release-signing-1',
                'public_key_file' => dirname(__DIR__, 2) . '/protocol/fixtures/v1/authority/test-authority-public.pem',
                'allow_private_network' => $allowPrivateNetwork,
                'package_download_hosts' => [(string) parse_url($authorityBase, PHP_URL_HOST)],
                'endpoints' => [
                    'activate' => $authorityBase . '/v1/licences/activate',
                    'validate' => $authorityBase . '/v1/licences/validate',
                    'deactivate' => $authorityBase . '/v1/licences/deactivate',
                    'update_check' => $authorityBase . '/v1/updates/check',
                    'package_authorisation' => $authorityBase . '/v1/updates/authorize-package',
                ],
            ],
            'runtime' => [
                'timeout' => 30,
                'validation_retry_seconds' => 21600,
                'update_check_seconds' => 43200,
                'lock_seconds' => 300,
                'admin_capability' => 'manage_options',
            ],
        ];
        $path = $directory . '/manifest.json';
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $path;
    }

    private function pluginFile(string $root, string $folder, string $main, string $version): string
    {
        $directory = $root . '/' . $folder;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Test plugin directory could not be created.');
        }
        $path = $directory . '/' . $main;
        file_put_contents($path, "<?php\n/**\n * Plugin Name: Test Plugin\n * Version: {$version}\n */\n");

        return $path;
    }

    /** @return array<string,string> */
    private function hashes(string $root): array
    {
        $hashes = [];
        foreach ($this->files($root) as $path) {
            $hashes[substr($path, strlen($root) + 1)] = (string) hash_file('sha256', $path);
        }

        return $hashes;
    }

    private function sources(string $root): string
    {
        $source = '';
        foreach ($this->files($root) as $path) {
            if (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
                $source .= (string) file_get_contents($path);
            }
        }

        return $source;
    }

    /** @return list<string> */
    private function files(string $root): array
    {
        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function temporary(string $name): string
    {
        $path = sys_get_temp_dir() . '/zlc-wordpress-' . $name . '-' . bin2hex(random_bytes(5));
        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('Test directory could not be created.');
        }

        return $path;
    }

    private function delete(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
