<?php

declare(strict_types=1);

namespace Mazaya\License\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mazaya\License\Guard\Gate;
use Mazaya\License\LicenseManager;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Puts the licence notice on the customer's screens without the product having
 * to include it.
 *
 * The banner is the only part of this package that would otherwise need a line
 * of code in the host application, and it is precisely the part that must not
 * be forgotten: a suspension is visible at the vendor's end immediately but
 * only stops the system days later, and a customer who was never shown the
 * reason experiences that as an outage with no warning.
 *
 * Injected only into complete HTML documents, immediately after <body>, and
 * only when there is something to say. Nothing is touched otherwise — and a
 * product that has placed the banner itself is left alone.
 */
class InjectLicenseNotice
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if (! config('license.inject_notice', true)) {
                return $response;
            }

            if ($request->expectsJson() || ! $this->isHtmlDocument($response)) {
                return $response;
            }

            $license = app(LicenseManager::class);

            if (! app(Gate::class)->isEnforcing()) {
                return $response;
            }

            if ($license->vendorNotice() === null && ! $license->state()->isWarning()) {
                return $response;
            }

            $html = (string) $response->getContent();

            // The product placed it itself; leave the page alone.
            if (str_contains($html, 'data-license-notice')) {
                return $response;
            }

            $banner = view('license::banner')->render();

            $response->setContent(preg_replace(
                '/(<body\b[^>]*>)/i',
                '$1'.$banner,
                $html,
                1,
            ) ?: $html);
        } catch (Throwable) {
            // A notice is never worth breaking a page over.
        }

        return $response;
    }

    private function isHtmlDocument(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            && stripos((string) $response->getContent(), '<body') !== false;
    }
}
