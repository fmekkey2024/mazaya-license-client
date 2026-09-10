<?php

declare(strict_types=1);

namespace Mazaya\License\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mazaya\License\LicenseManager;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Applies the licence decision to HTTP traffic.
 *
 * Note what it never does: throw, or return a 5xx. If this middleware itself
 * breaks, the request is allowed through — a bug in the licensing code is our
 * problem, not something a customer should discover as an outage.
 */
class EnforceLicense
{
    public function __construct(private readonly LicenseManager $license) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (config('license.mode') !== 'onprem' || $this->isExempt($request)) {
                return $next($request);
            }

            $state = $this->license->state();

            if ($state->isUsable()) {
                return $this->tag($next($request), $state->value);
            }

            // Read-only mode: reading, reporting and exporting all keep working.
            // Only the methods that change data are refused.
            if ($this->license->allowsReads() && $request->isMethodSafe()) {
                return $this->tag($next($request), $state->value);
            }

            return $this->refuse($request, $state->headline());
        } catch (Throwable) {
            return $next($request);
        }
    }

    private function isExempt(Request $request): bool
    {
        $patterns = (array) config('license.exempt', []);

        return $patterns !== [] && $request->is(...$patterns);
    }

    private function refuse(Request $request, string $headline): Response
    {
        $payload = [
            'headline' => $headline,
            'message'  => $this->license->message(),
            'state'    => $this->license->state()->value,
        ];

        // 402 rather than 403: it distinguishes a licensing stop from a
        // permissions bug at a glance in the logs, which saves a support call.
        if ($request->expectsJson()) {
            return response()->json($payload, 402);
        }

        return response()->view('license::locked', $payload, 402);
    }

    private function tag(Response $response, string $state): Response
    {
        $response->headers->set('X-License-State', $state);

        return $response;
    }
}
