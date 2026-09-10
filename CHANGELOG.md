# Changelog

All notable changes to `mazaya/license-client`.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-09-10

### Added

- **Laravel 10 support.** Nothing in the package needed changing — every
  framework API it uses exists in 10 — the constraint was simply too narrow and
  `composer require` refused to install. Verified end to end on Laravel 10.50.3:
  activation, enforcement, the configuration seal and recovery all behave
  identically. `Integrity::hostFiles()` now also reports `app/Http/Kernel.php`
  and `config/app.php`, which are Laravel 10's equivalents of
  `bootstrap/providers.php`.

### Fixed

- **`php artisan serve` was refused by the console guard**, so on an unlicensed
  or tampered installation the web server would not start at all and the
  customer could not reach the page explaining why. `serve` hosts the
  application rather than operating it, and the HTTP guard is what enforces
  there — gracefully, with a 402 and an explanation. It is now on the recovery
  allowlist. Production installations sit behind nginx and were unaffected;
  this only ever showed up when running locally.

### Note

Composer **blocks Laravel 10 by default** — the 10.x line carries five
unresolved security advisories. Installing it needs `--no-security-blocking` or
an explicit advisory-ignore policy. That is worth knowing before shipping a
Laravel 10 product to a customer who runs a security review.

## [1.2.1] - 2026-09-10

### Fixed

- **Activation failed on macOS and Windows.** Every fingerprint source was
  Linux-only — `/etc/machine-id`, `/proc/cpuinfo`, `/sys/class/net` — so on a
  developer's laptop each component came back empty and the licence server
  rejected the request with a validation error, which is a confusing way to
  learn the package does not run on your machine. Each component now has macOS
  and Windows sources, unreadable ones are omitted rather than sent empty, and a
  machine that can identify itself in no other way falls back to an id generated
  once and kept alongside the licence.

Production installations are unaffected: a Linux host still reports the same
four components as before.

## [1.2.0] - 2026-09-10

Editing the licensing code now invalidates the licence. Verified against 18
code-tampering scenarios on a live installation.

### Added

- **Code integrity in the seal.** Every PHP file in this package is hashed, and
  that digest is folded into the configuration seal. Editing a check, deleting a
  file, or adding one changes the digest and the licence stops verifying — a
  one-line comment is enough to trigger it.
- **An independent seal check in the halt guard**, deliberately not routed
  through the gate. The gate is what decides tampering, so editing the gate
  alone would otherwise have been enough to switch that decision off. A checker
  cannot be the only thing checking itself.
- The host application's `routes/*.php` and `bootstrap/` files are hashed and
  **reported on each heartbeat**, so a change is visible on the vendor's
  dashboard.

### Notes

The host application's own files are reported rather than sealed, on purpose.
They change with every release of the product, and sealing them would mean a
forgotten `license:reseal` after a deploy takes a paying customer offline. They
are also no longer load-bearing: stripping `license` from a route group changes
nothing, because the guard is prepended to the global middleware stack.

The limit is unchanged and worth restating: the integrity check is itself PHP on
a machine the customer controls. It now takes edits in at least two files that
both know about each other, and every one of those edits stops the heartbeat.
Only a bytecode encoder removes the possibility rather than raising its price.

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
