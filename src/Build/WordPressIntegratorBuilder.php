<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Build;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use Zithis\LicenceClient\ClientPackage;

final class WordPressIntegratorBuilder
{
    public const CONTRACT_VERSION = 1;

    private string $repositoryRoot;

    public function __construct(private ComposerAutoloadGenerator $composerAutoload)
    {
        $this->repositoryRoot = dirname(__DIR__, 2);
        if (!is_dir($this->repositoryRoot . '/src') || !is_dir($this->repositoryRoot . '/integrators/wordpress/src')) {
            throw new RuntimeException('The Licence Client package root is invalid.');
        }
    }

    public function build(WordPressIntegratorManifest $manifest, string $outputDirectory): WordPressIntegratorBuild
    {
        $outputDirectory = rtrim($outputDirectory, '/\\');
        if ($outputDirectory === '') {
            throw new RuntimeException('The WordPress integrator output directory is required.');
        }
        if (file_exists($outputDirectory)) {
            throw new RuntimeException('The WordPress integrator output directory must not already exist.');
        }

        $parent = dirname($outputDirectory);
        if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new RuntimeException('The WordPress integrator output parent could not be created.');
        }
        if (!mkdir($outputDirectory, 0775) && !is_dir($outputDirectory)) {
            throw new RuntimeException('The WordPress integrator output directory could not be created.');
        }

        try {
            $clientNamespace = $manifest->namespacePrefix() . '\\Client';
            $wordpressNamespace = $manifest->namespacePrefix() . '\\WordPress';
            $this->copyTransformedTree(
                $this->repositoryRoot . '/src',
                $outputDirectory . '/client',
                ['Zithis\\LicenceClient' => $clientNamespace],
                ['Build']
            );
            $this->copyTransformedTree(
                $this->repositoryRoot . '/integrators/wordpress/src',
                $outputDirectory . '/wordpress',
                [
                    'Zithis\\StandaloneWordPressIntegrator' => $wordpressNamespace,
                    'Zithis\\LicenceClient' => $clientNamespace,
                ]
            );
            $this->write($outputDirectory . '/wordpress/GeneratedConfig.php', $this->generatedConfig($manifest, $wordpressNamespace));
            $this->write($outputDirectory . '/composer.json', $this->composerSource($manifest->namespacePrefix()));
            $this->write($outputDirectory . '/bootstrap.php', $this->bootstrapSource($wordpressNamespace));
            $this->write($outputDirectory . '/INTEGRATION.md', $this->integrationGuide($manifest, $wordpressNamespace));
            $this->write($outputDirectory . '/LICENSE', $this->licenseText());
            $this->prepareComposerAutoload($outputDirectory);
            $this->assertRuntimeContract($outputDirectory, $manifest, $clientNamespace, $wordpressNamespace);
            $this->assertIsolation($outputDirectory);
            $clientPackage = $this->generatedClientPackage($outputDirectory);
            $manifestData = $this->writeManifest($outputDirectory, $manifest, $clientPackage);

            return new WordPressIntegratorBuild(
                $outputDirectory,
                $manifest->productCode(),
                $manifest->packageIdentifier(),
                $manifest->namespacePrefix(),
                $clientPackage['version'],
                $clientPackage['protocol_version'],
                $manifestData['file_count'],
                $manifestData['sha256']
            );
        } catch (Throwable $exception) {
            $this->deleteTree($outputDirectory);
            throw $exception;
        }
    }

    /** @param array<string,string> $replacements @param list<string> $excludedRoots */
    private function copyTransformedTree(
        string $sourceRoot,
        string $targetRoot,
        array $replacements,
        array $excludedRoots = []
    ): void {
        foreach ($this->files($sourceRoot) as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen(rtrim($sourceRoot, '/\\')) + 1));
            $root = explode('/', $relative, 2)[0];
            if (in_array($root, $excludedRoots, true)) {
                continue;
            }

            $target = $targetRoot . '/' . $relative;
            $contents = (string) file_get_contents($path);
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
                $contents = $this->rewriteNamespaces($contents, $replacements);
            }
            $this->write($target, $contents);
        }
    }

    /** @param array<string,string> $replacements */
    private function rewriteNamespaces(string $source, array $replacements): string
    {
        $tokens = token_get_all($source);
        $result = '';
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                $result .= $token;
                continue;
            }
            [$id, $text] = $token;
            if (in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $leading = str_starts_with($text, '\\') ? '\\' : '';
                $name = ltrim($text, '\\');
                foreach ($replacements as $from => $to) {
                    if ($name === $from || str_starts_with($name, $from . '\\')) {
                        $name = $to . substr($name, strlen($from));
                        break;
                    }
                }
                $text = $leading . $name;
            }
            $result .= $text;
        }

        return $result;
    }

    private function generatedConfig(WordPressIntegratorManifest $manifest, string $namespace): string
    {
        $payload = var_export($manifest->publicPayload(), true);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

final class GeneratedConfig
{
    /** @return array<string,mixed> */
    public static function data(): array
    {
        return {$payload};
    }

    private function __construct()
    {
    }
}
PHP;
    }

    private function composerSource(string $namespace): string
    {
        $payload = [
            'name' => 'zithis/generated-standalone-licence-runtime',
            'description' => 'Generated isolated Zithis standalone WordPress licence runtime.',
            'type' => 'library',
            'license' => 'MIT',
            'autoload' => [
                'psr-4' => [
                    $namespace . '\\Client\\' => 'client/',
                    $namespace . '\\WordPress\\' => 'wordpress/',
                ],
            ],
            'config' => [
                'autoloader-suffix' => $this->composerAutoloaderSuffix($namespace),
                'optimize-autoloader' => true,
                'sort-packages' => true,
                'platform-check' => false,
            ],
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    private function composerAutoloaderSuffix(string $namespace): string
    {
        return 'ZLC' . strtoupper(substr(hash('sha256', trim($namespace, ' \\')), 0, 20));
    }

    private function bootstrapSource(string $namespace): string
    {
        $pluginClass = $namespace . '\\Plugin';

        return <<<PHP
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

return {$this->export($pluginClass)};
PHP;
    }

    private function integrationGuide(WordPressIntegratorManifest $manifest, string $namespace): string
    {
        $pluginClass = $namespace . '\\Plugin';
        $runtimeClass = $namespace . '\\Runtime';

        return <<<MD
# {$manifest->productName()} licence integration

This directory is a generated, product-scoped build of the maintained Zithis Licence Client and standalone WordPress integrator. Do not edit generated files. Rebuild them from the maintained Licence Client build service.

The generated runtime uses Composer's `vendor/autoload.php`; it does not contain a custom PHP autoloader.

Load the integration before the plugin's business runtime:

```php
\$integratorClass = require __DIR__ . '/licence/bootstrap.php';

/** @var class-string<{$pluginClass}> \$integratorClass */
\$runtime = \$integratorClass::register(
    __FILE__,
    static function ({$runtimeClass} \$runtime): void {
        require __DIR__ . '/src/bootstrap.php';
    }
);
```

The registered bootstrap callback runs only when the signed `suite` entitlement permits business use. The licence administration page, validation scheduler and private updater remain available while business runtime is blocked.

Product code: `{$manifest->productCode()}`  
Package identifier: `{$manifest->packageIdentifier()}`  
Generated namespace: `{$manifest->namespacePrefix()}`
MD;
    }

    private function licenseText(): string
    {
        $path = $this->repositoryRoot . '/LICENSE';
        $contents = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($contents) || trim($contents) === '') {
            throw new RuntimeException('The Licence Client MIT licence file is unavailable.');
        }

        return rtrim($contents) . "\n";
    }

    private function prepareComposerAutoload(string $root): void
    {
        $this->composerAutoload->generate($root);

        if (!is_file($root . '/vendor/autoload.php')) {
            throw new RuntimeException('Composer did not generate vendor/autoload.php for the standalone licence runtime.');
        }
        if (is_dir($root . '/vendor/bin')) {
            $this->deleteTree($root . '/vendor/bin');
        }
    }

    private function assertRuntimeContract(
        string $root,
        WordPressIntegratorManifest $manifest,
        string $clientNamespace,
        string $wordpressNamespace
    ): void {
        $required = [
            'bootstrap.php',
            'composer.json',
            'LICENSE',
            'vendor/autoload.php',
            'wordpress/Plugin.php',
            'wordpress/Runtime.php',
            'wordpress/LicenceManager.php',
            'wordpress/Admin/AdminPage.php',
            'wordpress/Admin/AdminController.php',
            'wordpress/Http/AuthorityHttpPolicy.php',
            'wordpress/Update/UpdateManager.php',
            'client/Security/SignatureVerifier.php',
        ];
        foreach ($required as $relative) {
            if (!is_file($root . '/' . $relative)) {
                throw new RuntimeException('The generated WordPress integrator is missing required runtime file: ' . $relative);
            }
        }

        $bootstrap = (string) file_get_contents($root . '/bootstrap.php');
        $runtime = (string) file_get_contents($root . '/wordpress/Runtime.php');
        $licences = (string) file_get_contents($root . '/wordpress/LicenceManager.php');
        $http = (string) file_get_contents($root . '/wordpress/Http/AuthorityHttpPolicy.php');
        $updates = (string) file_get_contents($root . '/wordpress/Update/UpdateManager.php');
        $admin = (string) file_get_contents($root . '/wordpress/Admin/AdminPage.php');
        $signatures = (string) file_get_contents($root . '/client/Security/SignatureVerifier.php');
        $config = (string) file_get_contents($root . '/wordpress/GeneratedConfig.php');

        $checks = [
            ['passed' => str_contains($bootstrap, "vendor/autoload.php"), 'label' => 'Composer bootstrap'],
            ['passed' => str_contains($runtime, 'canUseBusinessRuntime') && str_contains($runtime, "add_action('plugins_loaded'"), 'label' => 'business runtime gate'],
            ['passed' => str_contains($licences, 'activate(') && str_contains($licences, 'validate(') && str_contains($licences, 'deactivate('), 'label' => 'licence activation and validation state handling'],
            ['passed' => str_contains($http, 'http_request_host_is_external') && str_contains($http, 'allowsPrivateNetwork'), 'label' => 'authority-aware WordPress HTTP policy'],
            ['passed' => str_contains($updates, 'upgrader_pre_download') && str_contains($updates, "hash_file('sha256'") && str_contains($updates, 'packageRequest'), 'label' => 'private update verification'],
            ['passed' => str_contains($admin, 'requires licence activation'), 'label' => 'licence recovery UI'],
            ['passed' => str_contains($signatures, 'openssl_verify'), 'label' => 'signature verification'],
            [
                'passed' => str_contains($config, var_export($manifest->productCode(), true))
                    && str_contains($config, var_export($manifest->packageIdentifier(), true)),
                'label' => 'generated product identity',
            ],
            ['passed' => str_contains($runtime, $wordpressNamespace), 'label' => 'isolated WordPress namespace'],
            ['passed' => str_contains($signatures, $clientNamespace), 'label' => 'isolated client namespace'],
        ];
        foreach ($checks as $check) {
            if (!$check['passed']) {
                throw new RuntimeException('The generated WordPress integrator failed the ' . $check['label'] . ' contract.');
            }
        }
    }

    /** @return array{file_count:int,sha256:string} */
    /** @param array{name:string,version:string,protocol_version:string} $clientPackage */
    private function writeManifest(
        string $root,
        WordPressIntegratorManifest $manifest,
        array $clientPackage
    ): array {
        $files = [];
        foreach ($this->files($root) as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            if ($relative === 'integration-manifest.json') {
                continue;
            }
            $files[$relative] = hash_file('sha256', $path);
        }
        ksort($files, SORT_STRING);
        $payload = [
            'contract_version' => self::CONTRACT_VERSION,
            'client_package' => [
                'name' => $clientPackage['name'],
                'version' => $clientPackage['version'],
                'protocol_version' => $clientPackage['protocol_version'],
            ],
            'product_code' => $manifest->productCode(),
            'package_identifier' => $manifest->packageIdentifier(),
            'namespace' => $manifest->namespacePrefix(),
            'files' => $files,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $path = $root . '/integration-manifest.json';
        $this->write($path, $json);

        return [
            'file_count' => count($files) + 1,
            'sha256' => (string) hash_file('sha256', $path),
        ];
    }

    /** @return array{name:string,version:string,protocol_version:string} */
    private function generatedClientPackage(string $root): array
    {
        $path = $root . '/client/ClientPackage.php';
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('The generated Licence Client package metadata source is unavailable.');
        }

        $source = (string) file_get_contents($path);
        $values = [];
        foreach ([
            'name' => 'NAME',
            'version' => 'VERSION',
            'protocol_version' => 'PROTOCOL_VERSION',
        ] as $key => $constant) {
            if (preg_match(
                '/public\s+const\s+' . preg_quote($constant, '/') . '\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/',
                $source,
                $matches
            ) !== 1) {
                throw new RuntimeException('The generated Licence Client package metadata is missing ' . $constant . '.');
            }
            $values[$key] = trim((string) ($matches[1] ?? ''));
        }

        if ($values['name'] !== ClientPackage::NAME
            || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $values['version']) !== 1
            || preg_match('/^\d+\.\d+$/', $values['protocol_version']) !== 1) {
            throw new RuntimeException('The generated Licence Client package metadata is invalid.');
        }

        return $values;
    }

    private function assertIsolation(string $root): void
    {
        foreach ($this->files($root) as $path) {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($path);
            if (str_contains($source, 'Zithis\\LicenceClient') || str_contains($source, 'Zithis\\StandaloneWordPressIntegrator')) {
                throw new RuntimeException('The generated WordPress integrator contains an unscoped namespace reference.');
            }
            $relative = str_replace('\\', '/', substr($path, strlen(rtrim($root, '/\\')) + 1));
            if (!str_starts_with($relative, 'vendor/') && str_contains($source, 'spl_autoload_register')) {
                throw new RuntimeException('The generated WordPress integrator contains a custom PHP autoloader outside Composer vendor code.');
            }
        }
    }

    /** @return list<string> */
    private function files(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    private function write(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('A generated WordPress integrator directory could not be created.');
        }
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('A generated WordPress integrator file could not be written.');
        }
    }

    private function deleteTree(string $path): void
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

    private function export(string $value): string
    {
        return var_export($value, true);
    }
}
