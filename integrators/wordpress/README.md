# Standalone WordPress Plugin Integrator

This directory contains the single maintained WordPress integration layer for independent plugins that do not depend on Zithis core.

The framework-independent client remains under `src/`. The WordPress integration source under `integrators/wordpress/src/` is transformed together with that client into a product-scoped namespace by the Composer-autoloaded `Zithis\LicenceClient\Build\WordPressIntegratorBuilder` service. The CLI generator is only a thin adapter over that same service. The manifest supplies a vendor namespace base; the generator appends a deterministic product/package hash so two products cannot emit the same PHP classes. Generated bundles are build outputs and must never be edited or treated as another client implementation.

## Build

Install this package's Composer dependencies first. Create a strict product manifest from `examples/manifest.json`, then run:

```bash
php tools/build-wordpress-integrator.php \
  --manifest=/absolute/path/product-licence.json \
  --output=/absolute/path/plugin/licence
```

The output directory must not already exist. The build service creates the generated runtime's own optimized Composer `vendor/autoload.php`; no custom PHP autoloader is emitted. Embed the resulting directory in the independent plugin release package.

## Plugin bootstrap

Load the generated integration from the plugin's main file before loading business providers or routes:

```php
$integratorClass = require __DIR__ . '/licence/bootstrap.php';

$integratorClass::register(
    __FILE__,
    static function ($runtime): void {
        require __DIR__ . '/src/bootstrap.php';
    }
);
```

The callback runs only when the product's signed `suite` entitlement permits business use. Licence recovery, scheduled validation and private updater hooks remain loaded when the business runtime is blocked.

## Ownership

The generated integrator owns only the customer-site WordPress boundary:

- WordPress HTTP transport;
- encrypted product-scoped local licence state;
- stable installation identity;
- one product validation/update schedule;
- licence administration UI;
- network-free update-transient injection;
- fresh package authorisation and checksum verification at download time.


New integrations derive their state-encryption key from WordPress secret material plus a random product-scoped seed stored as a non-autoloaded option, so a database-only disclosure does not expose the decryption key. Existing integrations that already have the earlier protected key file continue to use it. Deployments may define `ZITHIS_LICENCE_KEY_DIRECTORY` for an explicit protected shared key store; place that directory outside the public web root. Multi-node installations must share the WordPress database and secret material, or deliberately share the configured key directory. Back up whichever key material your deployment uses; losing it makes local activation state unreadable and requires reactivation.

Zithis core commerce and LicenceServer remain the commercial, licensing and distribution authorities. Protocol version `1.0` and the maintained client source remain unchanged.

## Authority transport policy

Production manifests require HTTPS licence endpoints and HTTPS package delivery. Local/private development is an explicit build-time authority mode; when enabled, the generated integrator admits only the exact configured authority or pinned package host through a request-scoped WordPress `http_request_host_is_external` filter, keeps unsafe-URL rejection enabled, and removes the filter immediately after the request. It never enables loopback/private networking globally and never relaxes unrelated WordPress HTTP requests.
