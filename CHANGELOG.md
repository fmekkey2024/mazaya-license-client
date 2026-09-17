# Changelog

## [1.11.6] - 2026-09-17

### Fixed

- **Windows path separators broke manifest matching.** `Guard\Integrity::fileHashes()`
  built its keys from the OS path separator, so a Windows install produced
  `src\Guard\Gate.php` while a manifest signed on Linux held `src/Guard/Gate.php`.
  The two file-hash maps therefore never matched: the files-digest differed, the
  server found no manifest to return, and the install stayed tampered (heartbeat
  showing "expires 1970"). The relative path is now normalised to forward slashes
  on every platform, so a Windows install computes the same digest — and verifies
  the same manifest — as the Linux build that signed it. (Line endings were
  already normalised in 1.11.3; this is the separator, the other half.)

## [1.11.5] - 2026-09-17

### Fixed

- **Host-app / config tamper is auto-suspended again.** v1.11.4 let the server
  skip auto-suspend whenever the Agent's own code matched a pre-approved manifest
  — but that also masked a broken *seal* (an edited host-app file under
  strict_integrity, or an edited sealed `.env` value), where the Agent code is
  untouched yet the installation is genuinely tampered. The Agent now reports the
  seal and the manifest as two separate signals, so the server auto-suspends on a
  broken seal while still not suspending a fresh `composer` install whose
  (authentic) manifest merely has not been fetched yet.

## [1.11.4] - 2026-09-17

### Added

- **Fetch-at-activation for the vendor manifest.** The Agent now reports a digest
  of its own package files on activation and on every heartbeat, and the server
  returns the pre-signed manifest matching that exact code. A plain
  `composer require`/`composer update` install (which never carries the gitignored
  `agent-manifest.mlic`) now picks the manifest up automatically on its first
  heartbeat, instead of reading as tampered forever. No baked per-product
  artifact needed. The server only ever returns a manifest it signed itself for
  those exact file hashes — it never signs a digest the client proposes, so
  edited code still cannot obtain a manifest that vouches for it.

### Fixed

- On the first heartbeat right after a `composer update`, the install still
  reports the previous (now-stale) integrity result before it can store the new
  manifest. The server no longer auto-suspends in that window: a digest that
  matches a pre-approved manifest is proof the code is authentic even when the
  file is not yet on disk.

## [1.11.3] - 2026-09-17

### Fixed

- **Cross-platform manifest hashing.** The Agent manifest now hashes line-ending-
  normalised content, so a Windows checkout (CRLF) verifies against a manifest
  signed on Linux (LF) instead of reading as tampered.

### Changed

- `license:install-scheduler` and `license:install-listener` print Windows
  instructions (schtasks / NSSM) when run on Windows, instead of cron/systemd.


## [1.11.2] - 2026-09-17

### Fixed

- **Windows compatibility.** The fingerprint collector and the listener's
  heartbeat subprocess silenced stderr with the Unix `/dev/null`, so on Windows
  every probe printed "The system cannot find the path specified". Now uses `NUL`
  on Windows and `/dev/null` elsewhere, so Windows hardware fingerprinting
  (machine-id / MAC via reg / getmac) works cleanly and quietly.


## [1.11.1] - 2026-09-17

### Changed

- **A live SSE connection now counts as reachability for the offline leash.**
  With the listener connected, the leash stays satisfied without frequent
  heartbeats, so the default check-in is relaxed to 6 hours. A genuinely offline
  install (SSE dropped *and* heartbeats failing) still force-stops within
  `max_offline_hours`. An install NOT running the listener automatically beats an
  hour before the leash would trip, so it stays safe without instant updates.


## [1.11.0] - 2026-09-17

### Added

- **Instant updates over SSE (Mercure).** A panel decision -- suspend, revoke,
  approve, renew -- reaches an installation in seconds instead of at the next
  poll. The server pushes a "wake" to the installation's topic on the Mercure
  hub; the new `license:listen` daemon receives it and runs an ordinary heartbeat.
- The wake is only a nudge: the real, signed verdict still comes from the
  heartbeat it triggers, so the channel needs no trust -- a forged wake merely
  causes a harmless heartbeat. If the hub is unreachable the scheduled heartbeat
  is the unchanged backstop, so this is pure latency, never a dependency.
- `license:listen` -- the long-running daemon (run under systemd/supervisor).
- `license:install-listener` -- installs its systemd unit (root), or prints it.
  `license:activate` now sets it up too, best-effort, alongside the scheduler.
- The client stores the hub URL and a subscribe-only token from the heartbeat/
  activation response automatically; nothing to configure.


## [1.10.1] - 2026-09-17

### Added

- **`license:activate` sets up automatic renewal itself.** After a successful
  activation it installs the scheduler cron, best-effort: run as root (or with
  sudo) it writes the cron and the licence renews on its own; run as a non-root
  user the activation still succeeds and it prints the one line to add, noting
  that ordinary web traffic renews the licence in the meantime.

### Fixed

- `license:install-scheduler`, when run with sudo, writes the cron to run as the
  **owner of the application directory** rather than root -- so `schedule:run`
  does not create caches and logs the web user (nginx/FPM) cannot read.


## [1.10.0] - 2026-09-17

### Added

- **Vendor-signed Agent manifest.** The Agent's own code is vouched for by an
  Ed25519 manifest the vendor signs at build time (`license:sign-agent-manifest`
  on the server), verified by the client with the public key it already trusts
  for tokens. Editing any Agent file -- a check, the Verifier, the fingerprint
  collector -- no longer verifies, and **`license:reseal` can no longer adopt
  the edit**: forging the manifest needs the product's private key, which never
  leaves the licence server. Only restoring the signed code, or a new vendor
  release, resumes it.

### Changed

- The Agent's own code left the resealable configuration seal; it is covered by
  the signed manifest instead. The seal still covers configuration and, in
  strict mode, the host application's own source -- which legitimately changes
  per deploy and stays adopt-able via the panel's approve-and-resume flow. **A
  reseal is required after upgrading**, and the signed manifest must be present
  (baked into the per-product build).

### Security

- Closes the v1.9.0 self-reseal gap: the seal was a symmetric HMAC keyed by the
  per-install secret, which lives on the customer's box, so a privileged
  attacker could recompute it. The manifest is asymmetric -- the customer's box
  holds only the public key, and swapping it breaks token verification too. This
  does not stop an attacker who removes the check itself (that is the bar code
  encoding raises); it removes the one-command self-reseal.


## [1.9.0] - 2026-09-17

### Added

- **Strict hardware binding** (`LICENSE_FP_STRICT`, on by default). A heartbeat
  from hardware the installation was not activated on is refused and the panel
  flags it as a possible copy. Any change of the bound hardware stops the
  install -- which is exactly what a copy onto a second server looks like. A
  genuine migration is re-authorised from the panel.
- **Offline leash** (`LICENSE_MAX_OFFLINE_HOURS`, default 4). Once no heartbeat
  has reached the licence server inside the window, the installation force-stops
  (`stopped`, reason `offline`) and stays stopped until a heartbeat succeeds and
  confirms the licence is valid. An unreachable server within the window is
  still survived; past it, it is not.
- **Reseal on the vendor's approval.** When the panel approves code it flagged
  as modified, the next heartbeat adopts the current code as the sealed baseline
  and the installation resumes -- no manual `license:reseal` needed.
- `License::stopReason()` distinguishes a vendor stop from an offline stop for
  the block page.

### Changed

- Heartbeat cadence is driven by the licence server's `check_in`; the scheduled
  beat runs every minute and reports when due. The server can ask for a beat as
  often as every two minutes, so a vendor decision or a reported code edit takes
  effect within about that time.


## [1.8.0] - 2026-09-17

### Added

- **Honour an explicit vendor stop immediately** (`LICENSE_HONOR_SERVER_STOP`, on
  by default). A `suspended`, `revoked` or `expired` verdict from the licence
  server now locks the installation at the very next heartbeat, instead of
  letting the current signed token run out its remaining life. It stays locked
  until a heartbeat returns an active licence, so the lock is cleared only from
  the vendor's panel. An unreachable server never triggers it — survival through
  a network outage is unchanged.
- New `stopped` state. Unlike `expired` (derived from the token's own clock) it
  is a live vendor decision, and it is a **hard** stop: reads are refused too,
  not just writes, regardless of the enforcement mode.


## [1.7.0] - 2026-09-13

### Added

- **Strict integrity mode** (`LICENSE_STRICT_INTEGRITY`, on by default). The seal
  now covers the entire application's source — every PHP, Blade and config file —
  not just this package. Editing any file anywhere invalidates the licence until
  an operator reseals. Runtime paths are excluded so ordinary operation does not
  self-lock: `storage/`, `bootstrap/cache/`, `vendor/`, `public/`, `.env`.

### Changed

- **`license:reseal` is now a full deploy step.** It flushes every stale cache,
  reseals against the code on disk, rebuilds the caches, and prints the status.
  A deploy or `composer update` moves the fingerprint and must be followed by
  one `php artisan license:reseal` — nothing else. `--no-cache` reseals only.


All notable changes to `mazaya/license-client`.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.6.1] - 2026-09-10

### Fixed

- **The scheduler silently capped how often an installation could report.** It
  ran the heartbeat every five minutes, so a two-minute check-in window — which
  the licence server is perfectly entitled to ask for — could not be honoured:
  the installation was told to return in two minutes and had no opportunity to
  until five had passed. Found by requesting frequent check-ins and then
  revoking a licence, and watching the installation take longer to hear about it
  than it had been told to. It now runs every minute with `--if-due`, which
  matches the sixty-second floor the server enforces. In normal operation it
  wakes, finds nothing due, and exits.

## [1.6.0] - 2026-09-10

### Added

- **The licence server now says when to come back**, and this client obeys it.
  Every heartbeat response carries a `check_in` interval, stored as the next due
  time. An operator can shorten it from the panel for a couple of hours, which
  is as close to watching an installation live as is possible without an inbound
  path into the customer's network — and that single outbound rule is what made
  the arrangement acceptable to their security team in the first place.
- **`license:heartbeat --if-due`**, and the scheduled run now happens every five
  minutes using it. It wakes, finds nothing due, and exits. That is what lets a
  shortened check-in window actually be honoured; a fixed six-hourly cron could
  not.
- **The licence notice is placed automatically** on the application's own pages,
  so a product needs no licensing code at all. `@include('license::banner')`
  still works for precise placement and is not duplicated;
  `LICENSE_INJECT_NOTICE=false` turns the injection off.

### Fixed

- **The locked page arrived without the vendor's explanation.** The global guard
  passed no message, so a customer saw only that the system had stopped — and
  the first thing anyone does then is telephone somebody to ask why.
- **A rejected request could overwrite the vendor's words.** An unauthorised
  heartbeat answers with its own explanation — "request timestamp outside the
  accepted window" — and that was being stored and shown to the customer as
  though the vendor had said it. Only real verdicts are kept now.
- A suspension is shown to the customer **from the moment it happens**, rather
  than silently counting down and stopping days later with no warning given.

### Note

The first report after requesting frequent check-ins still arrives on the
installation's existing schedule. Nothing can change that. For something
immediate, the customer's own administrator runs `php artisan license:heartbeat`.

## [1.5.1] - 2026-09-10

Both fixes come from rehearsing a real install end to end, on a licence with a
five-day TTL — a shape no earlier test had used.

### Fixed

- **The warning window is now derived from the token's own life**, capped at
  half of it. A licence issued with a five-day TTL sits permanently inside a
  seven-day warning window, so the banner never went away — and a warning that
  is always on is one nobody reads.
- **`license:doctor` no longer reports a problem on every short-TTL licence.**
  It was comparing the current token's remaining life against a fixed seven
  days, but that number is capped by the TTL: a five-day licence never has more
  than five days on it and is perfectly healthy. It now asks whether the token
  outlasts the next couple of renewal attempts, which is the thing that actually
  matters. The client cannot see the subscription end date at all — the server
  caps each token at it — so it was never a question the client could answer.

## [1.5.0] - 2026-09-10

### Added

- **`license:doctor`** — one command that says whether an installation was set
  up correctly and will stay that way. "Follow the instructions" is not
  verifiable, and every way an install goes wrong is silent: the wrong public
  key, a forgotten activation, a scheduler nobody set up, a firewall that was
  never opened. Each looks fine on the day and fails weeks later at the
  customer's site.

  It checks the environment, the configuration, the activation and its state,
  that the package is actually loaded, that the licence service is reachable,
  and that a heartbeat has run recently — then exits non-zero if anything needs
  fixing, so it can gate a deployment script.

  Verified against each of those four failures: it catches all of them and
  names the remedy.

## [1.4.0] - 2026-09-10

The heartbeat now runs on its own after an ordinary install, instead of quietly
never running.

### Added

- **Renewal from web traffic.** Laravel's scheduler only runs if the operating
  system calls `schedule:run` every minute, and that line is the step most often
  missed in an on-premise install — invisibly, because everything looks healthy
  for weeks and then the licence lapses for no apparent reason. Any request can
  now renew the licence in `terminate()`, after the response has been flushed,
  so no visitor ever waits on the licence server. A cache lock holds it to one
  attempt per interval across every process. Disable with
  `LICENSE_RENEW_ON_REQUEST=false`.
- **`license:install-scheduler`** writes the cron entry, or prints the exact line
  and the `crontab -e -u` command when not running as root. A step in a document
  is a step somebody skips.
- **`license:status` says so when the scheduler is not running** — if the last
  heartbeat is older than two scheduled runs, it prints the cron line to add.

### Note

Renewal from traffic does not replace the scheduler: an installation that
serves no requests still needs it. What it removes is the silent failure when
nobody set it up.

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
