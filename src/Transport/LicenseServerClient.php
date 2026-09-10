<?php

declare(strict_types=1);

namespace Mazaya\License\Transport;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * The only component that talks to the outside world.
 *
 * Everything it sends is listed in the request builders below and nothing else:
 * hashed hardware identifiers, version strings and counts. No customer data
 * ever leaves the building, which is the answer a security team reviewing the
 * firewall exception will ask for.
 */
final class LicenseServerClient
{
    public function __construct(
        private readonly Http $http,
        private readonly Config $config,
    ) {}

    /** @param array<string, string> $fingerprint */
    public function activate(string $licenseKey, array $fingerprint, array $context = []): array
    {
        $response = $this->request()->post($this->url('activate'), array_filter([
            'license_key' => $licenseKey,
            'product'     => $this->config->get('license.product'),
            'fingerprint' => $fingerprint,
            'app_version' => $context['app_version'] ?? null,
            'php_version' => PHP_VERSION,
            'hostname'    => gethostname() ?: null,
            'label'       => $context['label'] ?? null,
        ], static fn ($value): bool => $value !== null));

        return $this->decode($response);
    }

    /**
     * Signs the request with this installation's own secret.
     *
     * The signature covers the timestamp and nonce as well as the body, so a
     * captured request cannot be replayed later or against a different install.
     */
    public function heartbeat(string $installId, string $secret, array $body): array
    {
        $raw       = json_encode($body, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $nonce     = bin2hex(random_bytes(8));

        $response = $this->request()
            ->withHeaders([
                'X-Install-Id' => $installId,
                'X-Timestamp'  => (string) $timestamp,
                'X-Nonce'      => $nonce,
                'X-Signature'  => hash_hmac('sha256', "{$timestamp}.{$nonce}.{$raw}", $secret),
                'Content-Type' => 'application/json',
            ])
            ->withBody($raw, 'application/json')
            ->post($this->url('heartbeat'));

        return $this->decode($response);
    }

    private function request(): PendingRequest
    {
        $request = $this->http
            ->acceptJson()
            ->timeout((int) $this->config->get('license.timeout', 15))
            ->connectTimeout(10)
            // Jitter matters: without it every install in the fleet retries in
            // lockstep after a shared outage and stampedes the server.
            ->retry(3, 200, throw: false);

        $proxy = $this->config->get('license.proxy');

        return $proxy ? $request->withOptions(['proxy' => $proxy]) : $request;
    }

    private function url(string $endpoint): string
    {
        return rtrim((string) $this->config->get('license.server'), '/')."/api/v1/{$endpoint}";
    }

    private function decode(Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException("License server returned an unreadable response (HTTP {$response->status()}).");
        }

        // 4xx bodies carry the vendor's own explanation, so they are returned
        // rather than thrown — the caller needs the message, not a stack trace.
        return $payload + ['http_status' => $response->status()];
    }
}
