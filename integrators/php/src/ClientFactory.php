<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php;

use Zithis\LicenceClient\Contract\Clock;
use Zithis\LicenceClient\Contract\CredentialStore;
use Zithis\LicenceClient\Contract\InstallationIdentity;
use Zithis\LicenceClient\Contract\Logger;
use Zithis\LicenceClient\Contract\ProductDescriptor;
use Zithis\LicenceClient\Contract\Transport;
use Zithis\LicenceClient\LicenceClient;
use Zithis\LicenceClient\Protocol\RequestFactory;
use Zithis\LicenceClient\Protocol\ResponseDecoder;
use Zithis\LicenceClient\Security\Redactor;
use Zithis\LicenceClient\Security\SignatureVerifier;
use Zithis\LicenceClient\State\StateInterpreter;
use Zithis\LicenceClient\Value\Authority;
use Zithis\LicenceClient\Value\EndpointSet;

final class ClientFactory
{
    public function __construct(
        private Configuration $configuration,
        private CredentialStore $store,
        private InstallationIdentity $identity,
        private Clock $clock,
        private Logger $logger,
        private ProductDescriptor $product,
        private ?Transport $transport = null
    ) {
    }

    public function make(): LicenceClient
    {
        return new LicenceClient(
            $this->transport ?? new StreamTransport(
                $this->configuration->timeoutSeconds(),
                $this->configuration->maximumResponseBytes()
            ),
            $this->store,
            $this->identity,
            $this->clock,
            $this->logger,
            $this->product,
            new EndpointSet($this->configuration->endpoints()),
            new Authority($this->configuration->authorityId(), $this->configuration->authorityPublicKeys()),
            new RequestFactory(),
            new ResponseDecoder(new SignatureVerifier(), new StateInterpreter(), 300),
            new Redactor()
        );
    }
}
