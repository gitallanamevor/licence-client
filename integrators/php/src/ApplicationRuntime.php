<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php;

use Zithis\LicenceClient\Contract\Clock;
use Zithis\LicenceClient\Contract\Transport;
use Zithis\LicenceClient\Runtime\LicenceStateCodec;
use Zithis\LicenceClient\Runtime\Status;
use Zithis\LicenceClient\Runtime\StatusResolver;
use Zithis\LicenceClient\Integrator\Php\State\AtomicFile;
use Zithis\LicenceClient\Integrator\Php\State\EncryptedCredentialStore;
use Zithis\LicenceClient\Integrator\Php\State\InstallationIdentityStore;
use Zithis\LicenceClient\Integrator\Php\State\MetadataStore;
use Zithis\LicenceClient\Integrator\Php\State\ProductLock;
use Zithis\LicenceClient\Integrator\Php\State\SecretKeyStore;
use Zithis\LicenceClient\Integrator\Php\State\StateDirectory;
use Zithis\LicenceClient\Integrator\Php\Update\PackageTransport;
use Zithis\LicenceClient\Integrator\Php\Update\StreamPackageTransport;
use Zithis\LicenceClient\Integrator\Php\Update\UpdateManager;

final class ApplicationRuntime
{
    private function __construct(
        private Configuration $configuration,
        private ProductDescriptor $product,
        private LicenceManager $licences,
        private UpdateManager $updates,
        private MaintenanceRunner $maintenance
    ) {
    }

    public static function create(
        Configuration $configuration,
        ?callable $logger = null,
        ?Transport $transport = null,
        ?PackageTransport $packageTransport = null,
        ?Clock $clock = null
    ): self {
        $clock ??= new SystemClock();
        $files = new AtomicFile();
        $directory = new StateDirectory($configuration);
        $metadata = new MetadataStore($directory, $files);
        $keys = new SecretKeyStore($directory, $files);
        $store = new EncryptedCredentialStore(
            $configuration,
            $directory,
            $files,
            $keys,
            $metadata,
            $clock,
            new LicenceStateCodec()
        );
        $identity = new InstallationIdentityStore($configuration, $directory, $files);
        $product = new ProductDescriptor($configuration);
        $client = (new ClientFactory(
            $configuration,
            $store,
            $identity,
            $clock,
            new CallbackLogger($logger),
            $product,
            $transport
        ))->make();
        $licences = new LicenceManager(
            $configuration,
            $client,
            $store,
            $metadata,
            $clock,
            new StatusResolver()
        );
        $updates = new UpdateManager(
            $configuration,
            $licences,
            $metadata,
            $clock,
            $packageTransport ?? new StreamPackageTransport()
        );
        $maintenance = new MaintenanceRunner(
            $configuration,
            $licences,
            $updates,
            new ProductLock($directory)
        );

        return new self($configuration, $product, $licences, $updates, $maintenance);
    }

    public function boot(callable $businessBootstrap): bool
    {
        if (!$this->licences->canUseBusinessRuntime()) {
            return false;
        }
        $businessBootstrap($this);

        return true;
    }

    public function assertBusinessRuntime(): void
    {
        if (!$this->licences->canUseBusinessRuntime()) {
            throw new RuntimeUnavailable('The application business runtime is unavailable until its licence permits use.');
        }
    }

    public function status(): Status { return $this->licences->status(); }
    public function configuration(): Configuration { return $this->configuration; }
    public function product(): ProductDescriptor { return $this->product; }
    public function licences(): LicenceManager { return $this->licences; }
    public function updates(): UpdateManager { return $this->updates; }
    public function maintenance(): MaintenanceRunner { return $this->maintenance; }
}
