<?php

declare(strict_types=1);

namespace Mazaya\License;

use Illuminate\Contracts\Config\Repository as Config;
use Mazaya\License\Enums\LicenseState;
use Mazaya\License\Fingerprint\Collector;
use Mazaya\License\Guard\Gate;
use Mazaya\License\Storage\ClockGuard;
use Mazaya\License\Storage\StateStore;
use Mazaya\License\Token\Verifier;
use Mazaya\License\Transport\LicenseServerClient;
use RuntimeException;
use Throwable;

/**
 * The public surface of the package. Everything an application, an artisan
 * command or a Blade view needs, and nothing about how it works.
 */
final class LicenseManager
{
    public function __construct(
        private readonly Gate $gate,
        private readonly StateStore $store,
        private readonly Verifier $verifier,
        private readonly Collector $fingerprints,
        private readonly ClockGuard $clock,
        private readonly LicenseServerClient $client,
        private readonly Config $config,
    ) {}

    // ------------------------------------------------------------------ state

    public function state(): LicenseState
    {
        return $this->gate->state();
    }

    public function isActive(): bool
    {
        return $this->gate->state() === LicenseState::Active;
    }

    public function allowsWrites(): bool
    {
        return $this->gate->allowsWrites();
    }

    public function allowsReads(): bool
    {
        return $this->gate->allowsReads();
    }

    public function daysRemaining(): ?int
    {
        return $this->gate->daysRemaining();
    }

    public function expiresAt(): ?int
    {
        return $this->gate->expiresAt();
    }

    /** The vendor's own words, straight from the last heartbeat. */
    public function message(): ?string
    {
        return $this->store->lastMessage();
    }

    public function payload(): ?array
    {
        return $this->gate->payload();
    }

    public function installId(): ?string
    {
        return $this->store->installId();
    }

    public function lastHeartbeatAt(): ?string
    {
        return $this->store->lastHeartbeatAt();
    }

    /** True while the banner should be visible to everyone, not just admins. */
    public function shouldWarnEveryone(): bool
    {
        return $this->gate->state() === LicenseState::Grace;
    }

    // --------------------------------------------------------------- lifecycle

    /** First contact. Exchanges the customer's license key for an install identity. */
    public function activate(string $licenseKey, array $context = []): array
    {
        $response = $this->client->activate($licenseKey, $this->fingerprints->collect(), $context);

        if (($response['http_status'] ?? 500) >= 400) {
            throw new RuntimeException($response['message'] ?? 'Activation was refused by the license server.');
        }

        if (! isset($response['install_id'], $response['license_secret'], $response['license'])) {
            throw new RuntimeException('The license server did not return a complete activation.');
        }

        $this->store->credentials($response['install_id'], $response['license_secret']);
        $this->applyToken($response['license']);
        $this->store->recordHeartbeat($response['status'] ?? 'active', $response['message'] ?? null);
        $this->gate->flush();

        return $response;
    }

    /**
     * Renew. Returns the server's answer, and is deliberately quiet about
     * network failure: an unreachable server is a normal condition here, and
     * the current token is what keeps the system running through it.
     */
    public function refresh(): array
    {
        $installId = $this->store->installId();
        $secret    = $this->store->secret();

        if ($installId === null || $secret === null) {
            throw new RuntimeException('This installation has not been activated yet.');
        }

        $response = $this->client->heartbeat($installId, $secret, [
            'fingerprint' => $this->fingerprints->collect(),
            'app_version' => (string) $this->config->get('app.version', 'unknown'),
            'php_version' => PHP_VERSION,
            'hostname'    => gethostname() ?: null,
            'current_seq' => $this->store->seq(),
            'integrity'   => [
                'license_file_ok' => ! $this->store->integritySuspect(),
                'clock_ok'        => ! $this->clock->rolledBack(),
            ],
        ]);

        if (isset($response['server_time'])) {
            $this->store->witnessTime((int) $response['server_time']);
        }

        if (isset($response['license'])) {
            $this->applyToken($response['license']);
        }

        $this->store->recordHeartbeat($response['status'] ?? 'unknown', $response['message'] ?? null);
        $this->gate->flush();

        return $response;
    }

    /** Produces the request file for the air-gapped path. */
    public function offlineRequest(): string
    {
        return json_encode([
            'v'           => 1,
            'product'     => $this->config->get('license.product'),
            'install_id'  => $this->store->installId(),
            'license_key' => $this->config->get('license.key'),
            'fingerprint' => $this->fingerprints->collect(),
            'seq'         => $this->store->seq(),
            'generated'   => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Applies a license handed over by hand, with the same checks as an online one.
     *
     * Accepts either shape the vendor may send: a bare token for a renewal, or
     * an envelope carrying the install identity and secret. A machine that has
     * never been online has no other way to obtain those, and without them it
     * could never heartbeat if the network is later opened.
     */
    public function offlineApply(string $contents): array
    {
        [$token, $credentials] = $this->unwrap(trim($contents));

        $payload = $this->verifier->verify($token);

        if (($payload['product'] ?? null) !== $this->config->get('license.product')) {
            throw new RuntimeException('That license is for a different product.');
        }

        if ($credentials !== null) {
            $this->store->credentials($credentials['install_id'], $credentials['license_secret']);
        }

        if (! $this->applyToken($token)) {
            throw new RuntimeException('That license is older than the one already installed.');
        }

        $this->store->recordHeartbeat('active', 'Applied offline.');
        $this->gate->flush();

        return $payload;
    }

    /** @return array{0: string, 1: array{install_id: string, license_secret: string}|null} */
    private function unwrap(string $contents): array
    {
        $envelope = json_decode($contents, true);

        if (! is_array($envelope) || ! isset($envelope['license'])) {
            return [$contents, null];
        }

        $credentials = isset($envelope['install_id'], $envelope['license_secret'])
            ? ['install_id' => (string) $envelope['install_id'], 'license_secret' => (string) $envelope['license_secret']]
            : null;

        return [trim((string) $envelope['license']), $credentials];
    }

    private function applyToken(string $token): bool
    {
        try {
            $payload = $this->verifier->verify($token);
        } catch (Throwable) {
            return false;
        }

        $stored = $this->store->storeToken($token, $payload);

        if ($stored && isset($payload['srv_time'])) {
            $this->store->witnessTime((int) $payload['srv_time']);
        }

        return $stored;
    }
}
