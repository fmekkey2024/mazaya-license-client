<?php

declare(strict_types=1);

namespace Mazaya\License\Storage;

/**
 * Detects the local clock being wound back.
 *
 * `date -s` is the first thing anyone tries against a time-limited license, and
 * it costs nothing to catch: the server stamps every token with its own time,
 * and time is not supposed to run backwards.
 */
final class ClockGuard
{
    public function __construct(
        private readonly StateStore $store,
        private readonly int $toleranceSeconds = 3600,
    ) {}

    public function rolledBack(): bool
    {
        $highWaterMark = $this->store->maxSeenAt();

        if ($highWaterMark === 0) {
            return false; // nothing witnessed yet
        }

        // Tolerance absorbs ordinary NTP drift on a box with no time sync,
        // which is common enough on isolated networks to matter.
        return time() < ($highWaterMark - $this->toleranceSeconds);
    }

    public function driftSeconds(): int
    {
        $highWaterMark = $this->store->maxSeenAt();

        return $highWaterMark === 0 ? 0 : $highWaterMark - time();
    }
}
