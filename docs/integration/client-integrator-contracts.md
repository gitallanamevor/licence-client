# Licence Client Integrator Contracts

The client is created once per product context. It receives the following runtime contracts:

- `Transport`: sends a prepared HTTPS request and returns status, headers and raw body.
- `CredentialStore`: atomically loads, saves and clears encrypted activation credentials plus accepted local state by product code.
- `InstallationIdentity`: returns persistent installation UUID, scope, environment and optional canonical URI.
- `Clock`: supplies deterministic UTC-aware time.
- `Logger`: receives redacted diagnostics and request identifiers.
- `ProductDescriptor`: supplies immutable product code, package identifier and installed product version.

The composition root also supplies:

- `EndpointSet`: authority operation endpoints;
- `Authority`: pinned authority ID and public keys;
- protocol request/response services.

An integrator must not duplicate request codecs, response decoders, signature logic or state interpretation. It owns UI, scheduling, update registration, runtime gating and persistence implementation.

## Deactivation response-loss reconciliation

A retryable deactivation transport, protocol or server failure is ambiguous because the LicenceServer may have committed the remote deactivation before the confirmation was lost. The client performs one bounded validation reconciliation using the same stored activation credential only after such a retryable failure.

- Credential error `invalid_activation`, or authoritative `installation_not_active`, confirms that the credential no longer represents an active installation; the client clears the local credential and returns a successful deactivation result.
- A successful validation proves that the installation remains active; the original deactivation transport failure is retained and local state is preserved.
- A second transport, protocol, authority, compatibility, local-state, or unrelated credential failure does not claim deactivation success.
- Integrators must not add a separate retry loop or clear credentials merely because a network timeout occurred.


## Non-WordPress PHP application boundary

The maintained `Zithis\LicenceClient\Integrator\Php` runtime supplies a portable reference integration for Composer applications. It uses explicit configuration, native HTTPS streams, encrypted atomic file state, a shared installation UUID, one product lock and an application-invoked maintenance runner. Application boot never triggers a remote operation.

The generic updater may discover and stage a verified ZIP only. Application-specific extraction, migration, process restart, release switching and rollback remain outside this package. Multi-node applications representing one installation must share the complete product state directory.
