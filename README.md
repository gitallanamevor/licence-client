# Zithis Licence Client

`zithis/licence-client` is the maintained, framework-independent PHP client for Zithis LicenceServer. It provides a product-neutral protocol client plus reference runtime integrations for standalone PHP applications and independent WordPress plugins.

The package is intentionally public. Security does not depend on hiding client source: LicenceServer remains the authority, private signing keys remain server-side, and accepted responses are verified against explicitly pinned public keys.

## Install

```bash
composer require zithis/licence-client:^1.1
```

Requirements:

- PHP 8.1 or newer;
- JSON extension;
- OpenSSL extension.

Composer provides the only runtime autoloader:

```php
require __DIR__ . '/vendor/autoload.php';
```

No Laravel or WordPress bootstrap is required by the core client.

## What the core owns

The framework-independent client under `src/` owns:

- request construction and protocol versioning;
- signed-response decoding;
- RSA-SHA256 authority verification;
- request, product and installation binding;
- licence-state interpretation;
- stable error classification;
- recursive secret redaction.

Runtime integrations own transport, persistence, installation identity, scheduling, user interfaces, runtime gating, and update wiring. They must not duplicate the protocol or signature implementation.

## Non-WordPress PHP applications

Composer applications can use `Zithis\LicenceClient\Integrator\Php\ApplicationRuntime`. The maintained runtime provides HTTPS transport, encrypted durable state, stable installation identity, explicit maintenance scheduling, runtime gating, CLI recovery, and checksum-verified package staging.

See [`integrators/php/README.md`](integrators/php/README.md) and [`integrators/php/examples/licence-bootstrap.php`](integrators/php/examples/licence-bootstrap.php).

## Independent WordPress plugins

Independent plugins can generate a product-scoped isolated runtime with `Zithis\LicenceClient\Build\WordPressIntegratorBuilder`. The generated bundle contains the maintained client and WordPress integration under a deterministic private namespace and uses Composer-generated autoloading.

See [`integrators/wordpress/README.md`](integrators/wordpress/README.md) and [`integrators/wordpress/examples/manifest.json`](integrators/wordpress/examples/manifest.json).

## Security model

Production integrations should enforce all of the following:

- HTTPS for licence operations and package delivery;
- explicit authority ID and RSA public-key pinning;
- RSA keys of at least 2048 bits;
- no redirect following for authority or package requests;
- exact package-host allowlists;
- checksum verification before staged packages are trusted;
- encrypted activation state;
- no logging of licence keys, activation secrets, package tokens, signed payloads, signatures, authorization headers, or secret-bearing URLs.

WordPress installations derive new local encryption keys from WordPress secret material plus a product-scoped random seed. Existing installations that already have a protected key file continue to use it. A custom `ZITHIS_LICENCE_KEY_DIRECTORY` remains available for deployments that deliberately keep shared key material on protected storage outside the public web root.

See [`SECURITY.md`](SECURITY.md) and [`docs/security/secret-handling-and-authority-pinning.md`](docs/security/secret-handling-and-authority-pinning.md).

## Protocol

Protocol `1.0` supports activation, validation, deactivation, update checking, and package authorisation. Responses are signed and bound to the exact request, product, package, and installation.

Schemas and deterministic fixtures live under `protocol/`. The test suite also carries intentionally public, deterministic test-only signing material so verification does not depend on platform-specific OpenSSL key generation. It protects no Zithis service, customer, development, or production data and must never be configured as a trusted authority.

## Verify the package

```bash
composer validate --strict
composer test
composer verify-protocol
composer verify-security
```

Or run the maintained checks together:

```bash
composer verify
```

## Licence

MIT. See [`LICENSE`](LICENSE).
