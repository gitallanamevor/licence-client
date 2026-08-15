# Security Policy

## Supported versions

Security fixes are released on the latest maintained minor line. Consumers should use a Composer constraint that permits patch updates and keep the package current.

## Reporting a vulnerability

Please do not disclose suspected vulnerabilities in a public issue. Use GitHub private vulnerability reporting for this repository when available. Include the affected version, a concise reproduction, impact, and any relevant environment details. Do not include live licence keys, activation secrets, package tokens, private signing keys, or customer data.

## Trust model

The client treats LicenceServer as a remote authority whose responses must be cryptographically verified. Production integrations are expected to use HTTPS, explicitly pinned RSA public keys, exact product and installation binding, bounded response validity windows, and allowlisted package hosts.

The client does not attempt to defend against an attacker who already has arbitrary PHP execution inside the licensed application. Code running with the same application privileges can inspect process memory, invoke application APIs, and alter local enforcement. Commercial authority therefore remains server-side; signed responses and server-held private keys must never be replaced by secrecy of the client source.

## Secrets that must remain private

Never commit or publish:

- LicenceServer private signing keys;
- live licence keys or activation secrets;
- package download tokens or signed private URLs;
- GitHub, Composer, cloud, database, or deployment credentials;
- production `.env`, `auth.json`, private-key, PKCS#12, or keystore files.

The test suite contains intentionally public, deterministic test-only signing material so cryptographic tests are portable across supported PHP/OpenSSL environments. It protects no Zithis service, customer, development, or production data and must never be configured as a trusted authority. No LicenceServer production or development signing key is stored in this repository.
