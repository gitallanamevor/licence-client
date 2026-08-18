# AGENTS.md

## Purpose

This file is the permanent development contract for AI-assisted work in `zithis/licence-client`.

The repository is the source of truth. Build cumulatively on the current accepted implementation. Do not rely on chat history, old patch archives, or assumptions about Zithis Core, LicenceServer, or Plugin Builder when the repository says otherwise.

## Repository identity

`zithis/licence-client` is a public, framework-independent, product-neutral PHP licensing client for Zithis LicenceServer.

It has three distinct responsibilities:

1. `src/` — the maintained framework-independent protocol/security/runtime core.
2. `integrators/php/` — the maintained integration for non-WordPress PHP applications.
3. `integrators/wordpress/` — source for generated, product-scoped standalone WordPress integrations.

The core library must remain usable without Laravel or WordPress bootstrap.

Current package baseline:

- Composer package: `zithis/licence-client`
- PHP: `^8.1`
- Required extensions: `ext-json`, `ext-openssl`
- Core namespace: `Zithis\LicenceClient\`
- PHP integrator namespace: `Zithis\LicenceClient\Integrator\Php\`
- WordPress integrator source namespace: `Zithis\StandaloneWordPressIntegrator\`
- Runtime autoloading is Composer-owned.

## Before changing code

For every implementation, bug fix, refactor, protocol change, build change, or security change:

1. Read this file.
2. Read `README.md`.
3. Read the documentation relevant to the change, especially:
   - `docs/integration/client-integrator-contracts.md`
   - `docs/protocol/Zithis-Licence-Protocol-v1.md`
   - `docs/security/secret-handling-and-authority-pinning.md`
   - the applicable integrator README.
4. Inspect the existing implementation and tests before writing code.
5. Inspect `composer.json` before changing dependencies, autoloading, scripts, or packaging.
6. Determine which existing contracts and tests cover the affected behaviour.
7. Resolve contradictions between implementation, protocol fixtures/schemas, documentation, and tests before proceeding. Do not silently choose a new contract.

There is currently no repository project-memory system. Do not invent `PROJECT_MEMORY.md`, project-memory hooks, or phase-memory requirements unless explicitly requested as a separate repository change.

## Cumulative development rules

- Build on all accepted behaviour already present in the repository.
- Preserve unrelated behaviour.
- Make the smallest coherent change that fully satisfies the requested acceptance criteria.
- Provide only affected files when delivering a patch unless a complete package is explicitly requested.
- Put files in the correct existing ownership boundary and namespace.
- Reuse existing contracts, value objects, protocol helpers, security helpers, state primitives, builders, and integrator abstractions before creating new ones.
- Do not duplicate protocol, cryptographic, redaction, state interpretation, transport-policy, or build logic.
- Do not add speculative abstractions, unnecessary wrappers, aliases, compatibility shims, or fallbacks.
- Do not add legacy support unless the request explicitly changes the product contract to require it.
- Do not preserve obsolete behaviour merely because older code once supported it.
- Do not change public APIs, protocol shapes, namespace ownership, generated-runtime layout, or security policy incidentally.
- Prefer clear, explicit code over clever indirection.
- Keep dependencies minimal. Do not add a dependency when the repository already has a suitable implementation or PHP itself provides the required primitive.

## Core ownership boundary

`src/` owns product-neutral licensing semantics.

It owns:

- protocol request construction and operation versioning;
- JSON/base64url protocol primitives;
- signed-response decoding;
- authority/public-key policy;
- RSA-SHA256 signature verification;
- request, product, installation, package, and authority binding;
- licence-state interpretation;
- stable error classification;
- secret redaction;
- product-neutral runtime status/state codecs;
- WordPress integrator build/generation machinery.

It does not own application-specific UI, WordPress persistence, PHP application persistence, scheduling, deployment, or framework bootstrap.

Do not introduce WordPress or Laravel runtime dependencies into the core.

## Contract and value-object rules

The interfaces under `src/Contract/` are architectural boundaries. Extend or change them only when the capability genuinely belongs in the product-neutral client contract.

Prefer the existing immutable/value-oriented types under `src/Value/` and enums under `src/Enum/` over loosely structured arrays once data has crossed an external/configuration boundary.

Keep operation-specific behaviour aligned with `Operation`, `RequestFactory`, `LicenceClient`, response decoding, and the protocol schemas/fixtures.

Do not introduce product-specific codes, plugin-specific assumptions, customer-specific policy, or LicenceServer persistence concerns into the client.

## Protocol contract

Protocol `1.0` is a maintained public contract.

The canonical protocol material lives under:

- `protocol/manifest.json`
- `protocol/schema/v1/`
- `protocol/fixtures/v1/`
- `docs/protocol/Zithis-Licence-Protocol-v1.md`

Protocol changes must be treated as contract changes, not ordinary refactors.

When protocol behaviour changes:

- update all affected schema, fixture, implementation, tests, manifest/documentation together;
- preserve exact request/product/installation/authority binding;
- keep success and error response handling deterministic;
- do not add undocumented request or response fields;
- do not reintroduce legacy `site` or `plugin` protocol objects/keys;
- maintain compatibility only when the protocol contract explicitly requires it;
- run protocol verification.

Never weaken signature/binding validation to accept malformed, incomplete, stale, mismatched, or unsigned authority responses.

## Security invariants

Security does not depend on this repository being private. It is intentionally public.

LicenceServer remains the authority and private signing keys remain server-side.

Production integrations must preserve:

- HTTPS for licence operations and package delivery;
- explicit authority identity;
- explicitly pinned RSA public keys;
- RSA keys of at least 2048 bits;
- RSA-SHA256 verification;
- no redirect following for authority or package requests;
- exact package-host allowlists;
- request/product/installation/package binding;
- bounded signed-response validity;
- checksum verification before staged packages are trusted;
- encrypted durable activation state;
- recursive secret redaction.

Never log, expose, commit, serialize into diagnostics, or include in exceptions where avoidable:

- live licence keys;
- activation secrets;
- package tokens;
- signed private download URLs;
- authorization headers;
- signed payloads/signatures where they contain or protect sensitive authority material;
- private signing keys;
- production credentials.

The deterministic signing material under `protocol/fixtures/` and `tests/` is test-only public material. Never convert it into a production trust anchor.

Changes touching cryptography, authority pinning, redaction, endpoint policy, package download policy, state encryption, or secret storage require targeted regression validation and `composer verify-security`.

## Transport boundary

The core depends on `Zithis\LicenceClient\Contract\Transport`; it must not acquire a framework-specific HTTP client.

Runtime integrations own their transport implementation.

Transport implementations must preserve the security policy, including HTTPS requirements in production, redirect restrictions, bounded timeouts, stable error classification, and redaction.

Do not make network calls during simple status resolution when locally persisted state is sufficient.

## PHP application integrator

`integrators/php/` is the maintained runtime for standalone/non-WordPress PHP applications.

It owns:

- application configuration;
- stream-based authority transport;
- encrypted durable credential state;
- stable installation identity;
- state-directory ownership;
- atomic file writes;
- product locking;
- explicit maintenance execution;
- runtime gating;
- CLI recovery/operations;
- update discovery;
- checksum-verified package staging.

Important invariants:

- state directories must be explicit and safe; production must not silently accept insecure relative state locations;
- activation state remains encrypted at rest;
- installation identity remains stable across processes;
- maintenance owns due validation/update discovery rather than arbitrary request-time network traffic;
- locking prevents competing maintenance/state operations;
- generic update staging must not deploy/overwrite application files automatically;
- package transport must enforce the configured host/security policy.

Do not make the generic PHP integrator application-framework-specific.

## Standalone WordPress integrator

`integrators/wordpress/` is source material for isolated generated runtimes. It is not a second globally shared copy of `Zithis\LicenceClient`.

`WordPressIntegratorBuilder` and `WordPressIntegratorManifest` generate a deterministic, product-scoped runtime.

Generated runtimes must preserve these properties:

- deterministic output for the same manifest/input;
- private product-scoped namespace isolation;
- no runtime dependency on the global `Zithis\LicenceClient` namespace;
- no runtime dependency on `Zithis\StandaloneWordPressIntegrator`;
- Composer-generated autoloading;
- deterministic Composer autoloader suffix;
- inclusion of required licence/package metadata such as MIT licensing;
- independent products can coexist without namespace/autoloader collisions;
- the generated integration manifest accurately describes the shipment;
- licensing foundation can load while unlicensed business runtime remains gated;
- business bootstrap executes at most once per request;
- a valid state transition can admit business runtime without requiring unsafe duplicate registration.

Do not replace isolated generated runtimes with a shared global library dependency unless the architecture is explicitly redesigned and all consumers are coordinated.

Do not hand-roll an autoloader. Composer owns autoload generation.

## WordPress runtime responsibilities

The generated WordPress integration owns WordPress-specific concerns such as:

- WordPress transport;
- installation identity;
- encrypted credential/state persistence;
- local secret-key management;
- scheduling;
- status/admin presentation;
- update integration;
- WordPress logging;
- runtime admission/gating.

WordPress-specific code must stay in the WordPress integration boundary and must not leak into the framework-independent core.

Avoid direct database coupling where WordPress APIs already own the state boundary. Do not introduce `$wpdb` access as a shortcut for option/state behaviour.

## Runtime gating

Licensing infrastructure and business runtime are different concerns.

An integration must be able to expose the minimum licensing/recovery foundation required to activate or repair a licence while preventing protected business functionality from booting when the licence state does not admit it.

Do not solve gating by preventing the licensing foundation itself from loading.

Do not allow an unconfigured, invalid, expired beyond policy, revoked, suspended, or otherwise non-admitted state to boot protected business runtime.

Preserve the existing `Status`, `StatusResolver`, `LicenceStateCodec`, and integrator-specific runtime admission contracts rather than creating parallel status logic.

## Update and package handling

Update discovery and package authorisation remain authority-controlled.

Preserve:

- signed update metadata;
- product/installation binding;
- exact package host policy;
- package authorisation before protected download;
- checksum verification;
- safe staging;
- redaction of token-bearing URLs.

The generic PHP integrator stages packages but does not deploy application files.

WordPress update wiring belongs to the WordPress integration and must continue to respect WordPress lifecycle/update contracts.

Do not trust a package solely because it was downloaded successfully.

## Build-system rules

The WordPress integrator builder is production code.

Changes under `src/Build/`, `integrators/wordpress/`, Composer autoload generation, manifests, or shipment layout must preserve deterministic and isolated builds.

Never:

- copy arbitrary development/vendor state into generated products;
- rely on whatever Composer vendor happens to have loaded first;
- create duplicate active sources for the same generated classes;
- silently fall back to another plugin/application's Licence Client;
- generate non-deterministic namespace identities;
- weaken provenance/manifest validation to accept an invalid shipment.

When build behaviour changes, add or update focused build tests.

## No legacy / no bloat

For new work:

- no legacy compatibility layer unless explicitly required;
- no aliases for renamed concepts merely to preserve old callers;
- no duplicate old/new protocol paths;
- no runtime fallbacks that hide invalid configuration or packaging;
- no unnecessary service/container abstraction;
- no framework dependency for functionality already implemented framework-independently;
- no product-specific logic in this reusable package.

If obsolete behaviour must be removed, remove it cleanly and update tests/docs/contracts as one cumulative change.

## Testing and validation

Testing is part of implementation, not a follow-up activity.

The maintained commands are defined in `composer.json`:

```bash
composer validate --strict
composer test
composer verify-protocol
composer verify-security
composer verify
```

`composer verify` currently runs the maintained test, protocol, and security verification suites. `composer validate --strict` is separate and should also be run when Composer/package metadata is relevant.

For every change:

1. Preserve all relevant existing tests and validators.
2. Add automated validation for each new acceptance criterion where reasonably testable.
3. For a bug fix, add a regression test that fails for the defect and passes with the fix where practical.
4. Run the narrowest relevant tests while developing.
5. Before completion, run `composer verify`.
6. Run `composer validate --strict` when package metadata, Composer configuration, autoloading, scripts, dependencies, or release/package structure is touched.
7. Run build-specific verification when WordPress integrator generation changes.
8. Do not claim a command passed unless it was actually executed in the current working tree.
9. Report the exact commands executed and their PASS/FAIL results.
10. If a test fails because the implementation is wrong, fix the implementation.
11. Never weaken, delete, skip, or rewrite a valid existing test merely to make an incorrect implementation pass.
12. If an existing test is genuinely obsolete because an explicitly approved contract changed, explain the contract change and update implementation, tests, fixtures/schemas, and documentation coherently.
13. Identify any acceptance criterion that still requires manual QA.

A phase/task is not complete merely because code was written. It is complete when the requested behaviour is implemented, automated acceptance/regression validation is present where practical, relevant cumulative verification passes, and remaining manual QA is stated.

## Test design

Prefer tests that protect contracts and failure boundaries over tests that mirror implementation details.

High-value areas include:

- request construction and exact binding;
- signature verification and key policy;
- malformed/tampered/stale/mismatched responses;
- error classification;
- secret redaction;
- licence-state/status interpretation;
- encrypted state and stable installation identity;
- maintenance scheduling/locking;
- runtime admission;
- update/package authorisation and checksum handling;
- deterministic isolated WordPress builds;
- namespace/autoloader collision prevention;
- generated shipment integrity.

Use the existing lightweight test infrastructure under `tests/` unless there is a compelling approved reason to replace it. Do not add PHPUnit merely for familiarity when the repository already has a maintained test runner.

## Documentation

Update documentation when a public contract, integration procedure, security requirement, configuration shape, protocol shape, CLI behaviour, or build procedure changes.

Keep:

- `README.md` for package-level usage and guarantees;
- integrator READMEs for runtime-specific setup;
- `docs/protocol/` for protocol contracts;
- `docs/security/` for security/trust policy;
- `docs/integration/` for integration ownership/contracts.

Do not bury a required behavioural contract only in tests or only in chat.

## Public package discipline

This repository is a public Composer package. Treat public API and release changes deliberately.

Do not expose Zithis internal server implementation details through the client.

Do not commit:

- local Composer credentials;
- `.env` secrets;
- private keys;
- live customer/licence data;
- build artifacts containing secrets;
- machine-specific absolute paths.

Keep the package installable through normal Composer usage and compatible with its declared PHP constraint unless an explicit version-policy change is requested.

## Delivery requirements

When returning an implementation:

- provide only affected files unless the user explicitly requests the whole repository;
- preserve their repository-relative paths;
- summarize what changed and why;
- list exact validation commands actually executed;
- state PASS/FAIL for each;
- state any validation that could not be run and why;
- state remaining manual QA, if any;
- do not claim success for unexecuted checks.

Do not begin an unrelated next phase unless explicitly requested.
