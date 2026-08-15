# Secret Handling, Authority Pinning and Redaction

## Security boundary

The client is public source and must be safe to inspect. Security relies on server-held signing keys, pinned public keys, signed response binding, TLS, and protected runtime secrets. It does not rely on obscuring client code.

The client cannot protect secrets from code that already has arbitrary PHP execution inside the licensed application. Local runtime gating is therefore an application control, while commercial authority remains server-side.

## Secret ownership

Activation credentials are runtime secrets. Integrators must encrypt them at rest and must not write plaintext licence keys, activation secrets, package tokens, authorization values, signed private URLs, or private keys to logs, settings, exceptions, build metadata, or public repositories.

The core redactor recursively removes known secret-bearing fields and masks common secret query parameters and HTTP authorization values before logger callbacks receive context.

## WordPress state encryption

New WordPress integrations derive a 256-bit AES state-encryption key with HKDF-SHA256 from:

- strong WordPress secret key/salt material that is normally held outside the database; and
- a random product-scoped seed stored as a non-autoloaded WordPress option.

The seed is not sufficient to derive the encryption key without the WordPress secret material. Existing integrations that already have the earlier protected key file continue to read that file so an update does not invalidate local activation state.

Deployments may define `ZITHIS_LICENCE_KEY_DIRECTORY` to use an explicit protected filesystem key store, for example when key material must be shared deliberately across nodes. That directory should be outside the public web root and must be backed up.

## Generic PHP state encryption

The generic PHP integrator keeps its random 256-bit encryption key in the configured durable state directory and encrypts activation state with AES-256-GCM. The state directory must be an absolute protected path, should be outside public web roots, and must be shared by nodes that represent the same installation.

## Network transport

Production and staging licence endpoints use HTTPS. Native and WordPress transports verify TLS peers, reject self-signed certificates, and do not follow redirects. Package delivery is restricted to explicitly allowlisted hosts and verified by SHA-256 before a staged package is trusted.

Plain HTTP exists only for explicitly configured local/private development boundaries and must not be enabled for production delivery.

## Authority pinning

The client is configured with an authority ID and explicit public keys. A successful response is accepted only when:

- the authority ID matches exactly;
- the key ID is pinned;
- the declared algorithm is `RS256`;
- the pinned key is RSA and at least 2048 bits;
- the signature verifies over the exact decoded signed-payload bytes;
- request ID and nonce match the originating request;
- product and installation identities match;
- issue and expiry times are valid.

Key rotation is deliberate: add the new pinned public key before the old key is retired. Unknown keys are never downloaded automatically.

## Replay controls

Requests include a UUID, UTC timestamp, and cryptographically random nonce. Signed responses repeat the request UUID and nonce. LicenceServer must enforce its own bounded timestamp and nonce-replay policy; the client does not treat signed-response verification as a replacement for server-side replay protection.

## Repository hygiene

`composer verify-security` checks the public tree for private key material, common credential formats, internal development artifacts, private environment domains, and local workstation paths. If a Git repository is present, it also checks whether removed internal artifacts remain reachable in Git history.
