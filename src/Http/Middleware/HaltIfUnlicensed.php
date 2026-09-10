<?php

declare(strict_types=1);

namespace Mazaya\License\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mazaya\License\Guard\Gate;
use Mazaya\License\Guard\Seal;
use Mazaya\License\Storage\StateStore;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The hard stop.
 *
 * Prepended to the *global* middleware stack by the package's own service
 * provider, so it does not depend on anyone remembering to attach `license` to
 * a route group — and cannot be lifted by editing routes.
 *
 * Unlike the route-level middleware this one **fails closed**: any error while
 * deciding is treated as unlicensed. That is a deliberate trade. It means a
 * fault in licensing code becomes an outage for the customer, which is the
 * price of the guarantee that removing the licensing cannot leave the system
 * running.
 */
class HaltIfUnlicensed
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $gate = app(Gate::class);

            // Asked of the gate, not of config: a hosted install answers no
            // cheaply, while an on-premise one cannot talk its way out of it.
            if (! $gate->isEnforcing() || $this->isExempt($request)) {
                return $next($request);
            }

            // Verified here as well as in the gate, and deliberately not by
            // calling into it. The gate is what decides tampering, so editing
            // the gate alone would otherwise be enough to switch that decision
            // off — a checker cannot be the only thing checking itself.
            if (! $this->sealIntact()) {
                return $this->halt($request, 'This system\'s licensing configuration has been altered.');
            }

            $state = $gate->state();

            if ($state->isUsable()) {
                return $next($request);
            }

            // Reads survive an ordinary lapse so the customer can still get at
            // their own data. allowsReads() is what decides that, and it
            // withholds the concession when the configuration has been edited.
            if ($gate->allowsReads() && $request->isMethodSafe()) {
                return $next($request);
            }

            return $this->halt($request, $state->headline());
        } catch (Throwable) {
            return $this->halt($request, 'This system could not verify its license.');
        }
    }

    private function isExempt(Request $request): bool
    {
        $patterns = (array) config('license.exempt', []);

        return $patterns !== [] && $request->is(...$patterns);
    }

    private function sealIntact(): bool
    {
        $store = app(StateStore::class);

        $installId = $store->installId();

        // Never activated: there is nothing that could have been tampered with,
        // so let the ordinary state logic decide and keep the read-only
        // concession a fresh installation is entitled to.
        if ($installId === null) {
            return true;
        }

        $secret = $store->secret();
        $seal   = $store->seal();

        if ($secret === null || $seal === null) {
            return false;
        }

        return app(Seal::class)->matches($seal, $installId, $secret);
    }

    private function halt(Request $request, string $headline): Response
    {
        $payload = ['headline' => $headline, 'message' => null, 'state' => 'halted'];

        if ($request->expectsJson()) {
            return response()->json($payload, 402);
        }

        try {
            return response()->view('license::locked', $payload, 402);
        } catch (Throwable) {
            // Even the view layer may be gone. Say something rather than 500.
            return response($headline, 402);
        }
    }
}
