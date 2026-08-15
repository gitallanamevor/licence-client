<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator;

use Zithis\LicenceClient\LicenceClient;
use Zithis\LicenceClient\Protocol\RequestFactory;
use Zithis\LicenceClient\Protocol\ResponseDecoder;
use Zithis\LicenceClient\Security\Redactor;
use Zithis\LicenceClient\Security\SignatureVerifier;
use Zithis\LicenceClient\State\StateInterpreter;
use Zithis\LicenceClient\Value\Authority;
use Zithis\LicenceClient\Value\EndpointSet;
use Zithis\StandaloneWordPressIntegrator\Http\AuthorityHttpPolicy;
use Zithis\StandaloneWordPressIntegrator\Storage\WordPressCredentialStore;

final class ClientFactory
{
    public function __construct(
        private Configuration $configuration,
        private WordPressCredentialStore $store,
        private WordPressInstallationIdentity $identity,
        private SystemClock $clock,
        private WordPressLogger $logger,
        private ProductDescriptor $product,
        private AuthorityHttpPolicy $http
    ) {
    }

    public function make(): LicenceClient
    {
        return new LicenceClient(
            new WordPressTransport($this->configuration->timeout(), $this->http),
            $this->store,
            $this->identity,
            $this->clock,
            $this->logger,
            $this->product,
            new EndpointSet($this->configuration->endpoints()),
            new Authority($this->configuration->authorityId(), [
                $this->configuration->authorityKeyId() => $this->configuration->authorityPublicKey(),
            ]),
            new RequestFactory(),
            new ResponseDecoder(new SignatureVerifier(), new StateInterpreter(), 300),
            new Redactor()
        );
    }
}
