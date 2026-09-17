<?php

declare(strict_types=1);

namespace Mazaya\License\Guard;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Cache\Repository as Cache;
use Mazaya\License\Enums\LicenseState;
use Mazaya\License\Exceptions\InvalidLicense;
use Mazaya\License\Fingerprint\Collector;
use Mazaya\License\Guard\Seal;
use Mazaya\License\Storage\ClockGuard;
use Mazaya\License\Storage\StateStore;
use Mazaya\License\Token\Verifier;
use Throwable;

/**
 * The single place that answers "may this system run?".
 *
 * Two rules govern everything here. It never throws — a licensing fault must
 * never be the reason a customer sees a 500. And it never fails open — a fault
 * it cannot explain is treated as unlicensed, not as licensed.
 */
final class Gate
{
    private const CACHE_KEY = 'mazaya.license.state';

    private ?LicenseState $memoised = null;

    private ?array $payload = null;

    /** Why the system is stopped, when it is: 'vendor' | 'offline' | null. */
    private ?string $stopReason = null;

    public function __construct(
        private readonly StateStore $store,
        private readonly Verifier $verifier,
        private readonly ClockGuard $clock,
        private readonly Collector $fingerprints,
        private readonly Seal $seal,
        private readonly Cache $cache,
        private readonly Config $config,
        private readonly Manifest $manifest,
    ) {}

    /**
     * Whether this installation is under enforcement.
     *
     * Deliberately not read from config alone. `LICENSE_MODE=hosted` is a
     * one-word edit in a file the customer owns, and if that were the only
     * question asked it would switch enforcement off entirely — the cheapest
     * bypass in the system. A stored activation is proof this copy was licensed
     * as on-premise, and no later edit can unsay it.
     */
    public function isEnforcing(): bool
    {
        return $this->config->get('license.mode') === 'onprem'
            || $this->store->installId() !== null;
    }

    public function state(): LicenseState
    {
        $this->hydrate();

        return $this->memoised;
    }

    /** Writes require a genuinely valid license. No enforcement mode softens this. */
    public function allowsWrites(): bool
    {
        return $this->state()->isUsable();
    }

    /**
     * Reads survive unless the vendor explicitly chose full lockout. The
     * customer's data is theirs, and withholding it during a billing dispute is
     * a far worse problem than an unpaid invoice.
     */
    public function allowsReads(): bool
    {
        // Two states are a hard stop that the read-only concession never
        // extends to: an edited configuration (Tampered), and an explicit
        // vendor stop (Stopped). Both mean "nothing runs until this is put
        // right", so even reads are refused regardless of enforcement mode.
        if (in_array($this->state(), [LicenseState::Tampered, LicenseState::Stopped], true)) {
            return false;
        }

        return $this->state()->isUsable()
            || $this->config->get('license.enforcement', 'readonly') === 'readonly';
    }

    /** @return array<string, mixed>|null the verified payload */
    public function payload(): ?array
    {
        $this->hydrate();

        return $this->payload;
    }

    public function message(): ?string
    {
        return $this->store->lastMessage();
    }

    public function expiresAt(): ?int
    {
        return isset($this->payload()['exp']) ? (int) $this->payload()['exp'] : null;
    }

    /** Negative once expired. */
    public function daysRemaining(): ?int
    {
        $expiry = $this->expiresAt();

        return $expiry === null ? null : (int) floor(($expiry - time()) / 86400);
    }

    public function flush(): void
    {
        $this->memoised = null;
        $this->payload  = null;

        try {
            $this->cache->forget(self::CACHE_KEY);
        } catch (Throwable) {
            // nothing to flush
        }
    }

    // ------------------------------------------------------------------------

    /**
     * Populates both the state and the payload.
     *
     * They are cached together on purpose: caching the state alone means a
     * cache hit leaves the payload unset, and every accessor built on it
     * (expiry, seq, customer) silently reports nothing while the licence is
     * perfectly valid.
     */
    private function hydrate(): void
    {
        if ($this->memoised !== null) {
            return;
        }

        if (! $this->isEnforcing()) {
            $this->memoised = LicenseState::Active;

            return;
        }

        try {
            $cached = $this->cache->remember(
                self::CACHE_KEY,
                (int) $this->config->get('license.cache_ttl', 60),
                fn (): array => $this->evaluate(),
            );

            // The cached entry doubles as a clock witness.
            //
            // Winding the clock back does not only extend a licence -- it also
            // pushes every cache entry's expiry a year into the future, so a
            // cached "active" would survive precisely the rollback that should
            // have invalidated it. Recording when the entry was computed closes
            // that: time is not supposed to run backwards.
            if ((int) ($cached['at'] ?? 0) > time() + $this->tolerance()) {
                $this->cache->forget(self::CACHE_KEY);
                $cached = $this->evaluate();
            }

            $this->memoised = LicenseState::from($cached['state']);
            $this->payload  = $cached['payload'];
        } catch (Throwable) {
            // A broken cache driver must not decide licensing either way.
            $evaluated      = $this->evaluate();
            $this->memoised = LicenseState::from($evaluated['state']);
            $this->payload  = $evaluated['payload'];
        }
    }

    /** @return array{state: string, payload: array<string, mixed>|null, at: int} */
    private function evaluate(): array
    {
        $this->payload = null;

        try {
            $state = $this->decide();
        } catch (Throwable) {
            // Unexplained fault: refuse rather than assume the best.
            $state = LicenseState::Invalid;
        }

        return ['state' => $state->value, 'payload' => $this->payload, 'at' => time()];
    }

    private function tolerance(): int
    {
        return (int) $this->config->get('license.clock_tolerance', 3600);
    }

    /** Whether the sealed code and configuration are still intact. */
    public function integrityOk(): bool
    {
        return $this->sealIntact() && $this->manifest->verified();
    }

    /**
     * The two integrity components, reported separately on the heartbeat.
     *
     * The server needs to tell a real tamper apart from a manifest that merely
     * has to be fetched. A broken SEAL means the configuration or the host app
     * was edited — a genuine tamper the vendor must approve. A failed MANIFEST,
     * on the other hand, can simply mean a plain `composer` install has not
     * stored its (authentic) manifest yet; the server confirms that against the
     * digest and does not auto-suspend authentic code for it.
     */
    public function sealOk(): bool
    {
        return $this->sealIntact();
    }

    public function manifestOk(): bool
    {
        return $this->manifest->verified();
    }

    /** Why the system is stopped, when it is. Null unless state is Stopped. */
    public function stopReason(): ?string
    {
        $this->hydrate();

        return $this->stopReason;
    }

    private function decide(): LicenseState
    {
        $this->stopReason = null;

        $token = $this->store->token();

        if ($token === null) {
            // Never activated is an honest fresh install and keeps the
            // read-only concession. An install that *was* activated and whose
            // licence has since been removed is not the same thing.
            return $this->store->installId() === null
                ? LicenseState::Unlicensed
                : LicenseState::Tampered;
        }

        // Checked before the token itself: an edited .env or a half-deleted
        // state is not a licensing question, it is a tampering one, and the two
        // deserve different answers.
        if (! $this->sealIntact()) {
            return LicenseState::Tampered;
        }

        // The Agent's own code must match the manifest the vendor signed. Unlike
        // the seal, this cannot be reproduced on the customer's box: a self-reseal
        // of edited Agent code yields no valid manifest, so the edit never takes.
        if (! $this->manifest->verified()) {
            return LicenseState::Tampered;
        }

        // An explicit stop the vendor issued, honoured the instant it was heard.
        //
        // A suspension or revocation stops new tokens being signed but leaves
        // the current one cryptographically valid for the rest of its life. By
        // default that means the system keeps running for days after the
        // vendor pressed the button. When the vendor's policy is a hard stop,
        // the last verdict the server actually returned is honoured here — so
        // the system locks at the next heartbeat and stays locked until a
        // heartbeat brings back an active licence.
        //
        // Only an explicitly received negative verdict locks; an unreachable
        // server never does, which is what preserves survival through an
        // outage. That distinction is the whole point.
        if ($this->config->get('license.honor_server_stop', true)) {
            $verdict = $this->store->lastStatus();

            if (in_array($verdict, ['suspended', 'revoked', 'blocked', 'expired', 'activation_limit'], true)) {
                $this->stopReason = 'vendor';

                return LicenseState::Stopped;
            }
        }

        // The heartbeat leash.
        //
        // The system must have confirmed its licence with the server within the
        // last few hours or it stops — a hard stop, cleared only by a fresh
        // successful heartbeat once the network is back. This deliberately gives
        // up the survive-a-long-outage property in exchange for a short leash:
        // a copy taken off-network, or a box quietly firewalled away from us,
        // cannot keep running for the life of its token.
        $maxOffline = (int) $this->config->get('license.max_offline_hours', 4);

        if ($maxOffline > 0) {
            // A live SSE connection counts as reachability, exactly like a
            // heartbeat: whichever happened most recently is "last contact".
            $last        = $this->store->lastHeartbeatAt();
            $lastContact = max(
                $last !== null ? (int) strtotime($last) : 0,
                (int) ($this->store->lastSseAt() ?? 0),
            );

            if ($lastContact > 0 && $lastContact < time() - ($maxOffline * 3600)) {
                $this->stopReason = 'offline';

                return LicenseState::Stopped;
            }
        }

        try {
            $payload = $this->verifier->verify($token);
        } catch (InvalidLicense) {
            return LicenseState::Invalid;
        }

        if (($payload['product'] ?? null) !== $this->config->get('license.product')) {
            return LicenseState::Invalid;
        }

        // A token older than one already seen is a replay of a more generous
        // period, whatever else is true about it.
        if ((int) ($payload['seq'] ?? 0) < $this->store->seq()) {
            return LicenseState::Invalid;
        }

        if ($this->clock->rolledBack()) {
            return LicenseState::Invalid;
        }

        if (! $this->fingerprintMatches($payload['fp'] ?? [])) {
            return LicenseState::Invalid;
        }

        $this->payload = $payload;

        $now      = time();
        $expiry   = (int) ($payload['exp'] ?? 0);
        $graceEnd = $expiry + ((int) ($payload['grace_days'] ?? 0) * 86400);

        // The warning window is capped at half the token's own life.
        //
        // A licence issued with a five-day TTL is *always* inside a seven-day
        // warning window, so the banner would never go away — and a warning
        // that is permanently on is one nobody reads. Deriving it from the
        // token's own lifetime keeps "expiring soon" meaningful at any TTL.
        $tokenLife = max(1, $expiry - (int) ($payload['iat'] ?? $expiry));
        $warnFor   = min((int) $this->config->get('license.warn_days', 7) * 86400, intdiv($tokenLife, 2));
        $warnFrom  = $expiry - $warnFor;

        return match (true) {
            $now <= $warnFrom => LicenseState::Active,
            $now <= $expiry   => LicenseState::Expiring,
            $now <= $graceEnd => LicenseState::Grace,
            default           => LicenseState::Expired,
        };
    }

    /**
     * The licence must still match the configuration it was issued against.
     *
     * A licence present without a seal counts as tampering too — that is what
     * deleting the column, or restoring an old backup of it, looks like.
     */
    private function sealIntact(): bool
    {
        $installId = $this->store->installId();
        $secret    = $this->store->secret();
        $seal      = $this->store->seal();

        if ($installId === null || $secret === null || $seal === null) {
            return false;
        }

        return $this->seal->matches($seal, $installId, $secret);
    }

    /** @param array<string, string> $signed */
    private function fingerprintMatches(array $signed): bool
    {
        if ($signed === []) {
            return true; // nothing was bound, nothing to check
        }

        $current  = $this->fingerprints->collect();
        $present  = array_filter($signed, static fn ($v): bool => $v !== '');
        $matches  = 0;

        foreach ($present as $component => $value) {
            if (isset($current[$component]) && hash_equals((string) $value, $current[$component])) {
                $matches++;
            }
        }

        // Strict binding (the default now): every component the token carries
        // must still match, so any change of hardware — which is exactly what a
        // copy onto a second server looks like — stops the install. A legitimate
        // hardware change is re-authorised from the panel (reset fingerprint).
        if ($this->config->get('license.fingerprint_strict', true)) {
            return $matches === count($present);
        }

        $threshold = (int) $this->config->get('license.fingerprint_threshold', 3);

        return $matches >= min($threshold, count($present));
    }
}
