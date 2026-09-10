<?php

declare(strict_types=1);

namespace Mazaya\License\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mazaya\License\Guard\Gate;
use Mazaya\License\LicenseManager;
use Mazaya\License\Storage\StateStore;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Renews the licence from ordinary web traffic, so an installation stays
 * licensed even when nobody set up cron.
 *
 * Laravel's scheduler only runs if the operating system calls `schedule:run`
 * every minute. That one line is the most commonly missed step in an on-premise
 * install, and missing it is invisible: everything looks healthy for weeks and
 * then the licence lapses for no apparent reason. A system that serves any
 * traffic at all can renew itself instead.
 *
 * The work happens in terminate(), after the response has been flushed to the
 * browser, so no visitor ever waits on the licence server. A cache lock means
 * one attempt per interval no matter how many requests or workers there are.
 *
 * This does not replace the scheduler — a system with no traffic still needs
 * it, and `license:install-scheduler` sets it up — it removes the silent
 * failure when the scheduler is absent.
 */
class RenewLicense
{
    private const LOCK = 'mazaya.license.renewing';

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! config('license.renew_on_request', true)) {
                return;
            }

            if (! app(Gate::class)->isEnforcing()) {
                return;
            }

            $store = app(StateStore::class);

            if ($store->installId() === null) {
                return;   // never activated; there is nothing to renew
            }

            $interval = max(1, (int) config('license.heartbeat_hours', 6)) * 3600;
            $last     = $store->lastHeartbeatAt();

            if ($last !== null && strtotime($last) > time() - $interval) {
                return;   // renewed recently enough
            }

            // First request through the gate wins; the rest skip for a whole
            // interval, including after a failure, so an unreachable server is
            // retried on a schedule rather than on every single request.
            if (! Cache::add(self::LOCK, true, $interval)) {
                return;
            }

            // A shorter timeout than the scheduled run gets.
            //
            // This is opportunistic renewal: the current token is what keeps the
            // system running, and there is another chance on the next interval.
            // Waiting the full network timeout would pin an FPM worker for the
            // better part of a minute during an outage, which on a small pool is
            // a real cost for no benefit.
            config(['license.timeout' => min(5, (int) config('license.timeout', 15))]);

            app(LicenseManager::class)->refresh();
        } catch (Throwable) {
            // Renewal is best-effort. The current token is what keeps the
            // system running, and a failure here must never surface anywhere.
        }
    }
}
