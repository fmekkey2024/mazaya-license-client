<?php

declare(strict_types=1);

namespace Mazaya\License;

use Illuminate\Contracts\Config\Repository as Config;
use Mazaya\License\Enums\LicenseState;
use Mazaya\License\Fingerprint\Collector;
use Mazaya\License\Guard\Gate;
use Mazaya\License\Guard\Integrity;
use Mazaya\License\Guard\Seal;
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
        private readonly Seal $seal,
        private readonly Integrity $integrity,
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

    /**
     * Why the system is stopped, when it is: 'vendor' (the panel suspended,
     * revoked or expired it), 'offline' (no heartbeat has reached the server
     * inside the offline leash), or null when nothing is forcing a stop. It
     * lets the block page tell the customer whether to call the vendor or to
     * check their own network, rather than showing one blank wall for both.
     */
    public function stopReason(): ?string
    {
        return $this->gate->stopReason();
    }

    public function installId(): ?string
    {
        return $this->store->installId();
    }

    public function lastHeartbeatAt(): ?string
    {
        return $this->store->lastHeartbeatAt();
    }

    /**
     * What the licence server last said, when it was not simply "active".
     *
     * A suspension takes effect at the server immediately but only stops the
     * system when the current token lapses — days later. Without this the
     * customer sees nothing at all in between, and then one morning the system
     * stops with no warning they were ever given. Showing the vendor's own
     * words from the moment of suspension is both fairer and far more likely to
     * get the invoice paid before anything breaks.
     */
    public function vendorNotice(): ?string
    {
        $status = $this->store->lastStatus();

        if ($status === null || in_array($status, ['active', 'unknown'], true)) {
            return null;
        }

        return $this->store->lastMessage();
    }

    /** Seconds until the next heartbeat is due, per the server's own instruction. */
    public function secondsUntilCheckIn(): int
    {
        return $this->store->secondsUntilCheckIn(
            max(1, (int) $this->config->get('license.heartbeat_hours', 6)) * 3600
        );
    }

    public function isCheckInDue(): bool
    {
        return $this->secondsUntilCheckIn() <= 0;
    }

    /** The last word from the licence server: active, suspended, revoked, expired. */
    public function vendorStatus(): ?string
    {
        return $this->store->lastStatus();
    }

    /** True while the banner should be visible to everyone, not just admins. */
    public function shouldWarnEveryone(): bool
    {
        // A suspension or revocation concerns everyone using the system, not
        // only whoever happens to be an administrator.
        return $this->gate->state() === LicenseState::Grace
            || $this->vendorNotice() !== null;
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

        // Bind the licence to the configuration it was activated against, so a
        // later edit to .env invalidates it.
        $this->store->sealWith($this->seal->compute($response['install_id'], $response['license_secret']));

        $this->applyToken($response['license']);
        $this->store->storeMercure($response['mercure'] ?? null);
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

                // Whether the sealed code and configuration are intact. A false
                // here is an edited file — the server auto-suspends on it, so
                // the stop is registered centrally and cleared only from the
                // panel, not silently by reverting the edit.
                'code_ok'         => $this->gate->integrityOk(),

                'host_files'      => $this->integrity->hostFiles(base_path()),
            ],
        ]);

        if (isset($response['server_time'])) {
            $this->store->witnessTime((int) $response['server_time']);
        }

        if (isset($response['license'])) {
            $this->applyToken($response['license']);
        }

        $this->recordVerdict($response);

        $this->store->storeMercure($response['mercure'] ?? null);

        // The vendor approved the current code from the panel: adopt it as the
        // new sealed baseline so an edit they have accepted stops tripping the
        // integrity check. The server keeps sending this directive until the
        // client confirms code_ok=true, so a dropped heartbeat cannot strand
        // an installation the vendor already cleared.
        if (! empty($response['reseal'])) {
            $this->reseal();
        }

        $this->gate->flush();

        return $response;
    }

    /**
     * Statuses that represent the vendor's decision about this licence, as
     * opposed to something going wrong on the way there.
     */
    private const VERDICTS = ['active', 'suspended', 'revoked', 'expired', 'blocked', 'activation_limit'];

    /**
     * Only a real verdict is kept as the vendor's message.
     *
     * A rejected request answers with its own explanation — "signature
     * mismatch", "request timestamp outside the accepted window" — and storing
     * that as the vendor's words puts an internal transport error in front of
     * the customer, on the page they see when the system stops. It is both
     * confusing and untrue: the vendor never said it. Anything that is not a
     * verdict leaves the last real message standing.
     */
    private function recordVerdict(array $response): void
    {
        $status = $response['status'] ?? 'unknown';

        if (! in_array($status, self::VERDICTS, true)) {
            return;
        }

        // The server says when to come back. Honouring it is what lets an
        // operator watch an installation closely during a support call without
        // the whole fleet polling every two minutes for ever.
        $this->store->recordHeartbeat(
            $status,
            $response['message'] ?? null,
            isset($response['check_in']) ? (int) $response['check_in'] : null,
        );
    }

    /**
     * Re-bind the licence to the current configuration.
     *
     * A deliberate, legitimate change — moving the licence server, rotating a
     * key — has to be adoptable, or the only way out of one would be a full
     * re-activation. Deliberately not automatic: it is an explicit command an
     * operator runs, and it is recorded on the next heartbeat.
     */
    public function reseal(): bool
    {
        $installId = $this->store->installId();
        $secret    = $this->store->secret();

        if ($installId === null || $secret === null) {
            return false;
        }

        $this->store->sealWith($this->seal->compute($installId, $secret));
        $this->gate->flush();

        return true;
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
            $this->store->sealWith($this->seal->compute($credentials['install_id'], $credentials['license_secret']));
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
