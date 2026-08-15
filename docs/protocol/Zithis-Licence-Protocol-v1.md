# Zithis Product-Neutral Licence Protocol 1.0

## Status and ownership

Protocol `1.0` is the product-neutral contract used by `zithis/licence-client`. The client package owns request construction, response decoding, authority verification, state interpretation, and stable error classification. Zithis LicenceServer remains the server authority. Runtime integrators own transport, secure storage, installation identity, clock, logging, scheduling, administration, and update integration.

## Operations

The protocol defines five explicit POST operations:

| Operation | Purpose |
| --- | --- |
| `activate` | Exchange a product licence key for a product-bound activation credential and accepted licence state. |
| `validate` | Validate an existing activation credential and refresh accepted licence state. |
| `deactivate` | Deactivate the exact product and installation activation. |
| `update_check` | Return accepted licence state and optional signed update metadata for the configured product. |
| `package_authorisation` | Return short-lived product, installation, and release-bound package authority. |

Each operation has a language-neutral JSON Schema under `protocol/schema/v1`.

## Request envelope

Every request separates these concerns:

- `protocol`: protocol version, request UUID, UTC timestamp, and replay nonce;
- `client`: package name/version and supported protocol versions;
- `product`: immutable software product code, package identifier, and installed version;
- `installation`: persistent installation UUID, scope, environment, and optional canonical URI;
- `credential`: activation identifier and secret for authenticated operations only;
- `operation`: operation name and operation-specific parameters.

`operation.parameters` is always a JSON object. Operations with no parameters send `{}`, never `[]`.

## Replay controls

A request contains a unique `request_id`, cryptographically random `nonce`, and UTC `timestamp`. LicenceServer must enforce a bounded timestamp window and reject reused nonce combinations within its accepted request window. A successful response is signed and bound to the exact request ID and nonce.

## Successful response envelope

A successful response contains:

- `protocol_version`;
- `signed_payload`, encoded as unpadded base64url;
- `signature`, containing authority ID, key ID, algorithm `RS256`, and an unpadded base64url signature.

The signature covers the decoded `signed_payload` bytes exactly as transmitted. This avoids cross-language JSON canonicalisation ambiguity.

The decoded payload binds the result to protocol version, request, operation, product, package, installation, and a bounded issue/expiry window. A cryptographically valid response for another request or product is rejected.

## Error response envelope

An operation error contains a stable code, category, retryability flag, and request ID. It must not echo a licence key, activation secret, package token, signed payload, or other credential material.

Categories are `transport`, `protocol`, `compatibility`, `authority`, `credential`, `local_state`, and `server`.

## Licence states and runtime interpretation

Protocol `1.0` supports `active`, `validation_due`, `grace`, `expired`, `suspended`, `revoked`, and `deactivated`.

| State | Runtime mode |
| --- | --- |
| Active | Full |
| Validation due | Full, action required |
| Grace | Continuity while grace remains; restricted after grace expiry |
| Expired | Restricted |
| Suspended | Blocked |
| Revoked | Blocked |
| Deactivated | Blocked |

The client returns the decision; the runtime integrator owns application-specific enforcement.

## Update metadata and package authority

Update metadata is bound to product code, package identifier, exact release ID, version, and SHA-256 checksum by the signed response payload. Package authority returns an HTTPS download URI, short-lived package token, expiry, and checksum. Runtime integrators enforce their package-host allowlists and checksum verification; the core does not deploy files.

## Protocol negotiation

The client sends every protocol version it supports. A response must use a supported version. An unsupported version is a compatibility failure rather than a transport fallback.

## Fixtures

`protocol/fixtures/v1` contains deterministic requests, signed success responses, error responses, and failure cases using reserved example domains. The fixture authority public key is test-only. Its private key is generated only while fixtures are maintained and is not stored in the repository.
