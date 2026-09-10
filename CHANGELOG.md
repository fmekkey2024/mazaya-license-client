# Changelog

All notable changes to `mazaya/license-client`.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-10

First release. Verified end to end against the live Mazaya License Service from a
separate Laravel installation: 36 on-premise scenario assertions plus a 22-step
air-gapped round trip.

### Added

- Offline Ed25519 verification of signed licences, so the network is needed to
  *renew* a licence and never to *check* one. An installation keeps working for
  the full life of its current token through an outage.
- Scheduled heartbeat (every 6 hours by default) that renews the token, reports
  hardware drift and integrity flags, and fails quietly when the server is
  unreachable.
- Redundant local storage — a database row and a file on disk, reconciled by
  sequence number — so deleting either one self-heals.
- Anti-rollback: a token whose sequence is lower than the one already installed
  is refused, and a local clock wound back beyond tolerance invalidates the
  licence.
- Fuzzy hardware fingerprinting (3 of 4 components) so a VM migration or a
  replaced NIC does not lock a customer out of software they have paid for.
- Graceful enforcement: `active` → `expiring` → `grace` → `expired`. Reading,
  reporting and exporting survive expiry under the default `readonly` policy;
  only writes are refused, with HTTP 402.
- Air-gapped issuance: `license:offline-request` produces a request file, and the
  licence returned for it is applied with `license:offline-apply`.
- `LICENSE_MODE=hosted` short-circuits every check, so one codebase serves both
  our own SaaS tenants and an on-premise installation.
- Commands: `license:activate`, `license:heartbeat`, `license:status`,
  `license:offline-request`, `license:offline-apply`.
- `EnforceLicense` middleware and a `license::banner` view for the warning states.

### Security

- Heartbeats are signed with a per-installation HMAC secret over the timestamp,
  a nonce and the body, so a captured request cannot be replayed or reused
  against a different installation.
- Only hashed hardware identifiers, version strings and counters are transmitted.
  No customer data leaves the building.
