<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Mode
    |---------------------------------------------------------------------------
    | 'hosted' short-circuits every check, so the same codebase can serve our
    | own SaaS tenants and an on-premise install without a branch in the code.
    | Only 'onprem' enforces anything.
    */
    'mode' => env('LICENSE_MODE', 'hosted'),

    /*
    |---------------------------------------------------------------------------
    | Product
    |---------------------------------------------------------------------------
    | Must match the product slug on the license server. A token issued for a
    | different product is refused even if its signature is perfectly valid.
    */
    'product' => env('LICENSE_PRODUCT', 'unset'),

    'server' => env('LICENSE_SERVER', 'https://api-license.mazaya.dev'),
    'key'    => env('LICENSE_KEY'),

    // Corporate networks usually force outbound HTTP through a proxy.
    'proxy'   => env('LICENSE_PROXY'),
    'timeout' => (int) env('LICENSE_TIMEOUT', 15),

    /*
    |---------------------------------------------------------------------------
    | Signing keys
    |---------------------------------------------------------------------------
    | kid => base64url public key. Keep a retired key here until every token
    | signed with it has expired, otherwise a rotation locks this install out.
    */
    'keys' => array_filter([
        env('LICENSE_KID') => env('LICENSE_PUBLIC_KEY'),
    ]),

    /*
    |---------------------------------------------------------------------------
    | Enforcement
    |---------------------------------------------------------------------------
    | 'readonly' keeps the system readable and exportable with writes blocked.
    | 'lock' blocks everything. readonly is strongly preferred: the customer's
    | data is theirs, and a vendor that hides it during a billing dispute has a
    | much worse problem than an unpaid invoice.
    */
    'enforcement' => env('LICENSE_ENFORCEMENT', 'readonly'),

    // How close to expiry the admin-only warning starts.
    'warn_days' => (int) env('LICENSE_WARN_DAYS', 7),

    /*
    |---------------------------------------------------------------------------
    | Fingerprint
    |---------------------------------------------------------------------------
    | How many hardware components must still match. Below 4 on purpose: an
    | exact match would turn every VM migration into an outage.
    */
    'fingerprint_threshold' => (int) env('LICENSE_FP_THRESHOLD', 3),

    /*
    |---------------------------------------------------------------------------
    | Heartbeat
    |---------------------------------------------------------------------------
    */
    'heartbeat_hours' => (int) env('LICENSE_HEARTBEAT_HOURS', 6),

    /*
    |---------------------------------------------------------------------------
    | Renew from web traffic
    |---------------------------------------------------------------------------
    | Laravel's scheduler only runs if the operating system calls schedule:run
    | every minute, and that line is the most commonly missed step of an
    | on-premise install. With this on, any request can renew the licence after
    | its response has been sent — so a system that serves traffic keeps itself
    | licensed whether or not cron was ever set up. One attempt per interval,
    | never on the visitor's clock.
    */
    'renew_on_request' => env('LICENSE_RENEW_ON_REQUEST', true),

    /*
    |---------------------------------------------------------------------------
    | Strict integrity
    |---------------------------------------------------------------------------
    | When on, the seal covers the ENTIRE application's source — every PHP,
    | Blade and config file, minus runtime paths (storage, bootstrap/cache,
    | vendor, .env). Editing any file anywhere invalidates the licence until an
    | operator runs `php artisan license:reseal`.
    |
    | This is deliberate lockdown: a deploy, or any `composer update`, changes
    | the fingerprint and must be followed by a reseal, or the system stops.
    | Turn it off (the looser default the package ships with) if the product is
    | updated in place without a reseal step in the deploy.
    */
    'strict_integrity' => env('LICENSE_STRICT_INTEGRITY', true),

    /*
    |---------------------------------------------------------------------------
    | Show the notice automatically
    |---------------------------------------------------------------------------
    | Places the licence banner at the top of the application's own pages, so
    | the product needs no code at all. Turn it off to position the banner
    | yourself with @include('license::banner').
    */
    'inject_notice' => env('LICENSE_INJECT_NOTICE', true),

    // Seconds the evaluated state is cached. The verification itself costs
    // ~0.09ms; this exists to avoid a database read on every request.
    'cache_ttl' => (int) env('LICENSE_CACHE_TTL', 60),

    /*
    |---------------------------------------------------------------------------
    | Exempt paths
    |---------------------------------------------------------------------------
    | Always reachable, whatever the license says. Health checks and the login
    | screen belong here so a locked system can still be diagnosed and its
    | owner can still reach the page explaining why it is locked.
    */
    'exempt' => [
        'up',
        'health',
        'login',
        'logout',
        'license',
        'license/*',
    ],

    /*
    |---------------------------------------------------------------------------
    | Clock tolerance
    |---------------------------------------------------------------------------
    | Servers without NTP egress drift. Anything beyond this reads as a
    | deliberate rollback rather than drift.
    */
    'clock_tolerance' => (int) env('LICENSE_CLOCK_TOLERANCE', 3600),
];
