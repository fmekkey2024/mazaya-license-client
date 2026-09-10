# Changelog

All notable changes to `mazaya/license-client`.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-09-10

Removing the licensing now stops the system rather than freeing it. Verified
against 30 tamper scenarios on a live installation.

### Added

- **Configuration seal.** On activation the licence is bound to the settings it
  was issued against — mode, product, licence server, enforcement policy and
  signing keys — with a digest keyed by the per-installation secret. Editing any
  of them in `.env` invalidates the licence instead of changing behaviour, which
  closes the cheapest bypass in the system: `LICENSE_MODE=hosted`.
- **`license:reseal`** adopts a deliberate configuration change (a moved licence
  server, a rotated key) without a full re-activation.
- **Global enforcement.** A halt guard is prepended to the middleware stack by
  the package itself, so enforcement no longer depends on `license` being
  attached to a route group, and cannot be lifted by editing routes.
- **Console guard.** Artisan commands and queue workers are refused on an
  unlicensed installation, except the ones needed to recover: `license:*`,
  `migrate`, cache and config commands, and `schedule:run`.
- New `tampered` state, distinct from `invalid` because it is never an accident.

### Changed

- Enforcement is now **fail-closed**. Any error while deciding is treated as
  unlicensed. This is a deliberate reversal: a fault in licensing code will now
  take a customer down, which is the cost of guaranteeing that removing the
  licensing cannot leave the system running.
- Whether an installation is under enforcement is decided by **stored state**,
  not by `config('license.mode')`. A stored activation is proof this copy was
  licensed as on-premise, and no later edit to a file the customer owns can
  unsay it.
- An installation that was activated and whose licence has since been removed
  reads as `tampered`, not `unlicensed`. A never-activated install still reads
  as `unlicensed` and keeps the read-only concession.
- Tampering does not get the read-only concession — there is no honest reading
  of an edited configuration.

### Notes

Removing the package stops the application booting (HTTP 500), because Laravel
cannot resolve the missing service provider. That is the intended outcome, not a
crash to be handled.

This raises the cost of a bypass from one line to a deliberate, multi-file edit
by someone who understands what they are removing — and every one of those
routes stops the heartbeat, which surfaces on the vendor's dashboard within 48
hours. It is not, and cannot be, absolute: PHP source on a machine the customer
controls is always editable. Only a bytecode encoder changes that.

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
