<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator;

use Closure;
use RuntimeException;
use Throwable;
use Zithis\StandaloneWordPressIntegrator\Admin\AdminController;
use Zithis\StandaloneWordPressIntegrator\Admin\AdminPage;
use Zithis\StandaloneWordPressIntegrator\Http\AuthorityHttpPolicy;
use Zithis\StandaloneWordPressIntegrator\Storage\MetadataStore;
use Zithis\StandaloneWordPressIntegrator\Storage\SecretKeyStore;
use Zithis\StandaloneWordPressIntegrator\Storage\WordPressCredentialStore;
use Zithis\StandaloneWordPressIntegrator\Update\UpdateManager;
use Zithis\LicenceClient\Runtime\LicenceStateCodec;
use Zithis\LicenceClient\Runtime\StatusResolver;

final class Runtime
{
    private bool $registered = false;
    private bool $businessBooted = false;

    private function __construct(
        private string $pluginFile,
        private Closure $businessBootstrap,
        private Configuration $configuration,
        private ProductDescriptor $product,
        private LicenceManager $licences,
        private UpdateManager $updates,
        private Scheduler $scheduler,
        private AdminController $controller,
        private AdminPage $page
    ) {
    }

    public static function create(string $pluginFile, callable $businessBootstrap): self
    {
        $configuration = new Configuration(GeneratedConfig::data());
        $product = new ProductDescriptor($configuration, $pluginFile);
        $clock = new SystemClock();
        $metadata = new MetadataStore($configuration);
        $store = new WordPressCredentialStore(
            $configuration,
            new SecretKeyStore($configuration),
            $metadata,
            $clock,
            new LicenceStateCodec()
        );
        $identity = new WordPressInstallationIdentity($configuration);
        $logger = new WordPressLogger($configuration);
        $http = new AuthorityHttpPolicy($configuration);
        $client = (new ClientFactory($configuration, $store, $identity, $clock, $logger, $product, $http))->make();
        $licences = new LicenceManager(
            $configuration,
            $client,
            $store,
            $clock,
            new StatusResolver()
        );
        $updates = new UpdateManager($configuration, $product, $licences, $metadata, $http);
        $scheduler = new Scheduler($configuration, $licences, $store, $metadata, $updates);

        return new self(
            $product->pluginFile(),
            Closure::fromCallable($businessBootstrap),
            $configuration,
            $product,
            $licences,
            $updates,
            $scheduler,
            new AdminController($configuration, $licences, $updates, $scheduler),
            new AdminPage($configuration, $product, $licences, $metadata)
        );
    }

    public function register(): self
    {
        if ($this->registered) {
            return $this;
        }
        if (!function_exists('add_action')
            || !function_exists('register_activation_hook')
            || !function_exists('register_deactivation_hook')) {
            throw new RuntimeException('The WordPress plugin integration APIs are unavailable.');
        }

        $this->updates->register();
        $this->scheduler->register();
        $this->controller->register();
        $this->page->register();
        register_activation_hook($this->pluginFile, [$this->scheduler, 'activate']);
        register_deactivation_hook($this->pluginFile, [$this->scheduler, 'deactivate']);
        add_action($this->configuration->stateChangedHook(), [$this, 'stateChanged'], 30, 2);
        $this->registered = true;

        if (function_exists('did_action') && did_action('plugins_loaded') > 0) {
            $this->bootBusinessRuntime();
        } else {
            add_action('plugins_loaded', [$this, 'bootBusinessRuntime'], 20);
        }

        return $this;
    }

    public function bootBusinessRuntime(): void
    {
        if ($this->businessBooted || !$this->licences->canUseBusinessRuntime()) {
            return;
        }
        $this->businessBooted = true;
        try {
            ($this->businessBootstrap)($this);
        } catch (Throwable $exception) {
            $this->businessBooted = false;
            throw $exception;
        }
    }


    public function stateChanged(string $productCode, mixed $result = null): void
    {
        if (!hash_equals($this->configuration->productCode(), strtolower(trim($productCode)))) {
            return;
        }

        $this->licences->forgetCachedState();
        $this->bootBusinessRuntime();
    }

    public function configuration(): Configuration { return $this->configuration; }
    public function product(): ProductDescriptor { return $this->product; }
    public function licences(): LicenceManager { return $this->licences; }
    public function updates(): UpdateManager { return $this->updates; }
    public function scheduler(): Scheduler { return $this->scheduler; }
    public function businessRuntimeBooted(): bool { return $this->businessBooted; }
}
