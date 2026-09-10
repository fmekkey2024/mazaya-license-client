# Trying the licensing client locally

## 0. What your machine needs

- PHP 8.2+ with `ext-sodium` (`php -m | grep sodium` must print `sodium`)
- Composer
- An SSH key on GitHub that can read `fmekkey2024/mazaya-license-client`
  (`ssh -T git@github.com` should greet you as fmekkey2024)
- Outbound HTTPS to `api-license.mazaya.dev` — nothing else

## 1. A throwaway Laravel app

```bash
composer create-project laravel/laravel license-test
cd license-test
```

Point `.env` at any local database and run `php artisan migrate` once, so the
package has somewhere to keep its state.

## 2. Install the package

```bash
composer config repositories.mazaya vcs git@github.com:fmekkey2024/mazaya-license-client.git
composer config preferred-install.mazaya/* source
composer require mazaya/license-client:^1.2
php artisan migrate
```

The `preferred-install` line is not optional. A private GitHub repository cannot
be fetched as a zip over SSH — composer asks the GitHub API and gets a 404 — so
it has to clone the source instead.

## 3. Configure

Append to `.env`:

```dotenv
LICENSE_MODE=onprem
LICENSE_PRODUCT=hrms
LICENSE_SERVER=https://api-license.mazaya.dev
LICENSE_KEY=LK_live_b7116b73619d1ff347a6eb4316fc3a7d
LICENSE_KID=hrms-2026-09-0i0p
LICENSE_PUBLIC_KEY=uolCqv0ri6IUjqCAsdTB_KmWp1pWN35PpjKIPfq840A
LICENSE_ENFORCEMENT=readonly
```

## 4. Activate

```bash
php artisan license:activate --label=local-dev
php artisan license:status
```

Expect `Licensed`, ~30 days remaining, and your machine listed under
**Installations** at https://license.mazaya.dev.

## 5. Something to enforce

`routes/web.php`:

```php
Route::get('/report', fn () => 'reading works');
Route::post('/save', fn () => 'writing works');
```

Nothing to wire up — the guard is global. Then:

```bash
php artisan serve
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8000/report        # 200
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1:8000/save  # 200
```

## 6. Try to break it

Each of these should stop the app. Run `php artisan cache:clear` after every
change, or wait a minute for the cached verdict to lapse.

| Try | Expected |
|---|---|
| `LICENSE_MODE=hosted` in `.env` | 402 — the one-word bypass is sealed |
| Point `LICENSE_SERVER` anywhere else | 402 |
| Change `LICENSE_PUBLIC_KEY` | 402 |
| Add a comment to any file under `vendor/mazaya/license-client/src` | 402 |
| Delete a file from that directory | the app stops booting |
| `rm -rf vendor/mazaya` | HTTP 500 — Laravel cannot resolve the provider |
| `php artisan queue:work` while unlicensed | refused, exit 1 |
| `php artisan license:status` while unlicensed | still works — recovery path |

To watch the licence expire without waiting a month:

```bash
# suspend it from the panel at https://license.mazaya.dev, then
php artisan license:heartbeat     # reports "suspended" and issues no token
```

The existing token keeps the app running until it lapses — that is the design,
not a bug. Suspension takes effect within the token's TTL plus its grace period.

## 7. Recovering

If you make a change on purpose — moving the licence server, rotating a key:

```bash
php artisan license:reseal
```

If you get properly stuck, delete the `license_state` row and
`storage/app/license/`, then activate again. Five activation slots are on this
key, and re-activating the same machine reuses its slot rather than burning one.

## 8. When you are done

Remove the installation from **Installations** in the panel so it stops showing
as a silent install after 48 hours.
