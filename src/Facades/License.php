<?php

declare(strict_types=1);

namespace Mazaya\License\Facades;

use Illuminate\Support\Facades\Facade;
use Mazaya\License\Enums\LicenseState;
use Mazaya\License\LicenseManager;

/**
 * @method static LicenseState state()
 * @method static bool isActive()
 * @method static bool allowsWrites()
 * @method static bool allowsReads()
 * @method static int|null daysRemaining()
 * @method static int|null expiresAt()
 * @method static string|null message()
 * @method static array|null payload()
 * @method static string|null installId()
 * @method static string|null lastHeartbeatAt()
 * @method static bool shouldWarnEveryone()
 * @method static array activate(string $licenseKey, array $context = [])
 * @method static array refresh()
 * @method static string offlineRequest()
 * @method static array offlineApply(string $token)
 *
 * @see LicenseManager
 */
class License extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LicenseManager::class;
    }
}
