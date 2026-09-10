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
        };
    }
}
