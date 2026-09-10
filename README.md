# mazaya/license-client

Licensing client for on-premise Mazaya systems.

Verifies a signed license **locally** and renews it against the Mazaya License
Service. The network is needed to renew, never to check — an install behind a
broken VPN keeps working for the life of its current token.

Requires PHP 8.2+ with `ext-sodium`, and Laravel 10, 11, 12 or 13.

## Install

```bash
composer require mazaya/license-client
php artisan migrate
php artisan license:activate LK_live_xxxxxxxx --label=production
php artisan license:install-scheduler
php artisan license:doctor          # confirms the install is correct
```

`license:doctor` exits non-zero when something needs fixing, so a deployment
script can refuse to finish on a half-configured install.

`.env`:

```dotenv
LICENSE_MODE=onprem
LICENSE_PRODUCT=hrms
LICENSE_SERVER=https://api-license.mazaya.dev
LICENSE_KID=hrms-2026-09-0i0p
LICENSE_PUBLIC_KEY=uolCqv0ri6IUjqCAsdTB_KmWp1pWN35PpjKIPfq840A
# LICENSE_PROXY=http://proxy.customer.local:3128
```

`LICENSE_MODE=hosted` (the default) short-circuits every check, so the same
codebase serves our own SaaS tenants unchanged.

## Enforce

Nothing to wire up. The package prepends its own guard to the global middleware
stack, so every route is covered from the moment it is installed.

The `license` route middleware is still available if you want a per-route
read-only policy on top:

```php
Route::middleware(['web', 'license'])->group(/* ... */);
```

`@include('license::banner')` in your layout renders the warning states.

## Tamper resistance

On activation the licence is sealed to the configuration it was issued against —
mode, product, server, enforcement and signing keys. Editing any of them in
`.env` invalidates the licence rather than changing behaviour, so
`LICENSE_MODE=hosted` stops a licensed installation instead of freeing it.

After a change you made **on purpose** — a moved licence server, a rotated key —
adopt it with:

```bash
php artisan license:reseal
```

The seal also covers **this package's own code**. Every PHP file in it is
hashed, so editing a check, deleting a file or adding one invalidates the
licence rather than disabling it.

Your application's own files are not sealed — they change with every release,
and sealing them would mean a forgotten `license:reseal` after a deploy takes a
paying customer offline. Their hashes are reported on each heartbeat instead, so
a change is visible on the vendor's dashboard. They are not load-bearing either:
the guard is global, so removing `license` from a route group changes nothing.

Enforcement is fail-closed: an error while deciding is treated as unlicensed,
and removing the package stops the application booting.

## Keeping the licence renewed

Two independent paths, so a missed step is not fatal:

1. **Web traffic.** Any request renews the licence after its response has been
   sent, at most once per interval. An installation that serves traffic stays
   licensed with no setup at all.
2. **The scheduler**, for installations that may sit idle:

```bash
php artisan license:install-scheduler     # as root: writes /etc/cron.d/…
php artisan license:install-scheduler --print
```

`php artisan license:status` warns if neither has run recently.

## Commands

| Command | Purpose |
|---|---|
| `license:activate` | First contact; exchanges the key for an install identity |
| `license:heartbeat` | Renew (also scheduled every 6h automatically) |
| `license:status` | What state this install is in, and why |
| `license:offline-request` | Produce a `.lreq` file when the network is unavailable |
| `license:offline-apply` | Apply a `.lic` file issued by hand |
| `license:reseal` | Adopt a deliberate configuration change |
| `license:install-scheduler` | Install the cron entry the scheduler needs |
| `license:doctor` | Check the install is correct and will stay licensed |

## What it sends

Hashed hardware identifiers, version strings and the current sequence number.
No customer data ever leaves the building.

## Firewall

One outbound host, HTTPS only: `api-license.mazaya.dev`.
