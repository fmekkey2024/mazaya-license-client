<?php

declare(strict_types=1);

namespace Mazaya\License\Enums;

/**
 * What the license itself says. Whether reads survive in a bad state is an
 * enforcement policy, decided by the Gate — not another state here, or the same
 * situation ends up reportable under two different names.
 */
enum LicenseState: string
{
    /** Valid, with time to spare. */
    case Active = 'active';

    /** Valid, expiring soon. Warn admins only. */
    case Expiring = 'expiring';

    /** Past expiry, inside the grace window. Fully functional, warn everyone. */
    case Grace = 'grace';

    /** Past grace. */
    case Expired = 'expired';

    /** Never activated. */
    case Unlicensed = 'unlicensed';

    /** A license is present but does not verify, or is not for this install. */
    case Invalid = 'invalid';

    /**
     * The licensing configuration has been edited since activation, or the
     * local state has been partially removed. Distinct from Invalid because it
     * is never an accident.
     */
    case Tampered = 'tampered';

    /**
     * The licence server explicitly told this installation to stop —
     * suspended, revoked, blocked, or expired — on its last heartbeat.
     *
     * Distinct from Expired, which is derived from the token's own clock: this
     * is a live decision the vendor made, honoured the moment it was heard
     * rather than waiting for the current token to lapse. It stays until a
     * heartbeat brings back an active licence, so the vendor's panel is what
     * clears it.
     */
    case Stopped = 'stopped';

    public function isUsable(): bool
    {
        return in_array($this, [self::Active, self::Expiring, self::Grace], true);
    }

    public function isWarning(): bool
    {
        return in_array($this, [self::Expiring, self::Grace], true);
    }

    public function severity(): string
    {
        return match ($this) {
            self::Active   => 'ok',
            self::Expiring => 'warning',
            self::Grace    => 'danger',
            default        => 'critical',
        };
    }

    public function headline(): string
    {
        return match ($this) {
            self::Active     => 'Licensed',
            self::Expiring   => 'Your license expires soon',
            self::Grace      => 'Your license has expired',
            self::Expired    => 'This system is not licensed',
            self::Unlicensed => 'This system has not been activated',
            self::Invalid    => 'This system\'s license could not be verified',
            self::Tampered   => 'This system\'s licensing configuration has been altered',
            self::Stopped    => 'This system has been stopped by your vendor',
        };
    }
}
