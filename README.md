# mazaya/license-client

Licensing client for on-premise Mazaya systems.

Verifies a signed license **locally** and renews it against the Mazaya License
Service. The network is needed to renew, never to check — an install behind a
broken VPN keeps working for the life of its current token.

## Install

```bash
composer require mazaya/license-client
php artisan migrate
php artisan license:activate LK_live_xxxxxxxx --label=production
```

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

Enforcement is fail-closed: an error while deciding is treated as unlicensed,
and removing the package stops the application booting.

## Commands

| Command | Purpose |
|---|---|
| `license:activate` | First contact; exchanges the key for an install identity |
| `license:heartbeat` | Renew (also scheduled every 6h automatically) |
| `license:status` | What state this install is in, and why |
| `license:offline-request` | Produce a `.lreq` file when the network is unavailable |
| `license:offline-apply` | Apply a `.lic` file issued by hand |
| `license:reseal` | Adopt a deliberate configuration change |

## What it sends

Hashed hardware identifiers, version strings and the current sequence number.
No customer data ever leaves the building.

## Firewall

One outbound host, HTTPS only: `api-license.mazaya.dev`.
