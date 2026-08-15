# Non-WordPress PHP Application Integrator

This directory contains the maintained framework-neutral runtime integration for PHP applications that do not run inside WordPress and do not depend on Zithis core.

The product-neutral client remains under `src/`. The PHP application integrator is Composer-autoloaded as `Zithis\LicenceClient\Integrator\Php\` and supplies the runtime responsibilities that the client intentionally does not own: HTTPS transport, encrypted durable state, installation identity, explicit maintenance scheduling, runtime gating, command-line recovery and verified package staging.

## Product registration

Register the application in Zithis LicenceServer with:

- integration type `php_application`;
- distribution type `php_archive`, `composer_package`, or `external`;
- an immutable package identifier matching the application release manifest;
- an enabled licence offering owned by that software product.

Private `php_archive` and `composer_package` releases remain ZIP packages containing a root `zithis-release.json`. Composer releases must also contain a matching root `composer.json`. External-distribution products do not receive LicenceServer-hosted packages.

## Composer installation

```bash
composer require zithis/licence-client
```

The application must retain one exact installed version and package identifier in its integration configuration. Protocol version `1.0` remains unchanged.

## Bootstrap

Create one durable bootstrap file outside public web roots where practical. It must return an `ApplicationRuntime`:

```php
use Zithis\LicenceClient\Integrator\Php\ApplicationRuntime;
use Zithis\LicenceClient\Integrator\Php\Configuration;

$runtime = ApplicationRuntime::create(new Configuration($configuration));

$runtime->boot(static function (ApplicationRuntime $runtime): void {
    require __DIR__ . '/business/bootstrap.php';
});
```

`boot()` performs no remote request. The callback runs only when the locally verified signed `suite` entitlement permits application use. Licence recovery and maintenance remain available while the business runtime is blocked. Applications that prefer an exception boundary may call `assertBusinessRuntime()`.

## Durable state

`runtime.state_directory` must be an absolute, protected and durable path outside replaceable release directories. The integrator creates one product/package-scoped directory containing:

- a stable installation UUID;
- an automatically generated 256-bit encryption key;
- AES-256-GCM encrypted activation credential and accepted licence state;
- validation and update metadata;
- product-scoped file locks.

Files are written atomically with restrictive permissions. Multi-node applications representing one installation must mount the same state directory on every node. The directory must be backed up. Losing the encryption key makes the local activation state unreadable and requires reactivation.

## Maintenance scheduling

The integrator never performs validation or update discovery automatically on every application request. Invoke one explicit maintenance run from the application's existing scheduler, queue worker or operating-system cron:

```php
$report = $runtime->maintenance()->run();
```

One product-scoped file lock prevents overlapping runs. Due validation and update discovery are evaluated from the accepted licence state and stored metadata.

The packaged CLI can call the same boundary:

```bash
vendor/bin/zithis-licence /absolute/path/licence-bootstrap.php maintenance
vendor/bin/zithis-licence /absolute/path/licence-bootstrap.php status
vendor/bin/zithis-licence /absolute/path/licence-bootstrap.php validate
vendor/bin/zithis-licence /absolute/path/licence-bootstrap.php check-update
```

Activation reads the key from standard input instead of a command-line argument, preventing it from appearing in the process list:

```bash
printf '%s\n' "$LICENCE_KEY" | vendor/bin/zithis-licence /absolute/path/licence-bootstrap.php activate
```

## Updates and deployment ownership

`UpdateManager::discover()` obtains signed product-bound update metadata. `UpdateManager::stage()` obtains a fresh package authorisation, enforces HTTPS and the configured host allowlist, verifies release identity, expiry and SHA-256 checksum, and atomically writes the ZIP into an application-owned staging directory.

The generic integrator does **not** extract, install, switch symlinks, run migrations, restart processes or roll back deployments. Those actions are application-specific and remain owned by the application's existing deployment system. A deployment must verify the staged package's root `zithis-release.json` and use its normal transactional release procedure.

## Security boundaries

- Production and staging licence endpoints must use HTTPS; plain HTTP is accepted only for a `local` integration environment.
- Authority public keys are pinned explicitly and may include multiple key IDs for controlled rotation.
- Package downloads require HTTPS and an exact allowlisted hostname.
- Licence keys, activation secrets, package tokens and signed payloads must not be logged.
- The optional logger callback receives only the client's redacted context.
- The framework-neutral client, protocol codecs and signature verifier are reused directly; no alternative protocol implementation exists.

See `examples/licence-bootstrap.php` for a complete configuration shape.
