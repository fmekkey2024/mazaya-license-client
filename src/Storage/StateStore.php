<?php

declare(strict_types=1);

namespace Mazaya\License\Storage;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Holds the license locally, in two independent places.
 *
 * Deleting one of them is the most obvious way to try to reset a license, so
 * the database row and the file on disk back each other up: whichever carries
 * the higher sequence number wins, and the other is repaired from it.
 */
final class StateStore
{
    private const TABLE = 'license_state';

    private ?array $cachedRow = null;

    public function __construct(private readonly string $path) {}

    // ------------------------------------------------------------------ read

    public function token(): ?string
    {
        $row  = $this->row();
        $file = $this->readFile();

        // The higher sequence is the more recent license, whichever side it is on.
        if ($file !== null && (int) ($file['seq'] ?? 0) > (int) ($row['seq'] ?? 0)) {
            return $file['token'] ?? null;
        }

        if ($row['token'] ?? null) {
            return $row['token'];
        }

        return $file['token'] ?? null;
    }

    public function seq(): int
    {
        return max((int) ($this->row()['seq'] ?? 0), (int) ($this->readFile()['seq'] ?? 0));
    }

    /** Highest server timestamp ever witnessed — the clock-rollback baseline. */
    public function maxSeenAt(): int
    {
        return max((int) ($this->row()['max_seen_at'] ?? 0), (int) ($this->readFile()['max_seen_at'] ?? 0));
    }

    public function installId(): ?string
    {
        return $this->row()['install_id'] ?? $this->readFile()['install_id'] ?? null;
    }

    public function secret(): ?string
    {
        $secret = $this->row()['secret'] ?? $this->readFile()['secret'] ?? null;

        return $secret !== null ? (string) $secret : null;
    }

    public function seal(): ?string
    {
        $seal = $this->row()['seal'] ?? $this->readFile()['seal'] ?? null;

        return $seal !== null ? (string) $seal : null;
    }

    public function lastStatus(): ?string
    {
        return $this->row()['last_status'] ?? null;
    }

    public function lastMessage(): ?string
    {
        return $this->row()['last_message'] ?? null;
    }

    public function lastHeartbeatAt(): ?string
    {
        return $this->row()['last_heartbeat_at'] ?? null;
    }

    /** When the licence server asked this installation to report next. */
    public function nextCheckInAt(): ?string
    {
        return $this->row()['next_check_in_at'] ?? null;
    }

    /** Seconds until the next heartbeat is due; negative once it is overdue. */
    public function secondsUntilCheckIn(int $default): int
    {
        $next = $this->nextCheckInAt();

        if ($next !== null) {
            return strtotime($next) - time();
        }

        $last = $this->lastHeartbeatAt();

        return $last === null ? -1 : (strtotime($last) + $default) - time();
    }

    /** True when one copy was missing or behind — worth reporting home. */
    public function integritySuspect(): bool
    {
        $row  = $this->row();
        $file = $this->readFile();

        if (($row['token'] ?? null) === null && ($file['token'] ?? null) === null) {
            return false; // never activated; nothing to tamper with yet
        }

        return ($row['token'] ?? null) === null
            || $file === null
            || (int) ($row['seq'] ?? 0) !== (int) ($file['seq'] ?? 0);
    }

    // ----------------------------------------------------------------- write

    public function credentials(string $installId, string $secret): void
    {
        $this->put(['install_id' => $installId, 'secret' => $secret]);
    }

    public function sealWith(string $seal): void
    {
        $this->put(['seal' => $seal]);
    }

    /**
     * Persist a newly issued license.
     *
     * Refuses anything older than what is already stored: replaying a captured
     * token from a more generous period is the cheapest attack there is, and it
     * costs one comparison to close.
     */
    public function storeToken(string $token, array $payload): bool
    {
        $seq = (int) ($payload['seq'] ?? 0);

        if ($seq < $this->seq()) {
            return false;
        }

        $this->put([
            'token'       => $token,
            'seq'         => $seq,
            'max_seen_at' => max($this->maxSeenAt(), (int) ($payload['srv_time'] ?? 0)),
        ]);

        return true;
    }

    public function witnessTime(int $timestamp): void
    {
        if ($timestamp > $this->maxSeenAt()) {
            $this->put(['max_seen_at' => $timestamp]);
        }
    }

    public function recordHeartbeat(string $status, ?string $message, ?int $checkInSeconds = null): void
    {
        $this->put([
            'last_status'       => $status,
            'last_message'      => $message,
            'last_heartbeat_at' => now()->toDateTimeString(),
            'next_check_in_at'  => $checkInSeconds === null
                ? null
                : now()->addSeconds(max(60, $checkInSeconds))->toDateTimeString(),
        ]);
    }

    // ---------------------------------------------------------------- internals

    /** Writes both copies. A failure on either side must not take the app down. */
    private function put(array $attributes): void
    {
        $this->cachedRow = null;

        try {
            $existing = DB::table(self::TABLE)->first();

            if ($existing === null) {
                DB::table(self::TABLE)->insert($attributes + [
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table(self::TABLE)->where('id', $existing->id)
                    ->update($attributes + ['updated_at' => now()]);
            }
        } catch (Throwable) {
            // Database unreachable; the file copy still carries the license.
        }

        try {
            $merged = array_merge($this->readFile() ?? [], $this->row() ?? [], $attributes);
            unset($merged['id'], $merged['created_at'], $merged['updated_at']);

            @mkdir(dirname($this->path), 0750, true);
            @file_put_contents($this->path, json_encode($merged, JSON_PRETTY_PRINT), LOCK_EX);
            @chmod($this->path, 0640);
        } catch (Throwable) {
            // Read-only filesystem; the database copy still carries the license.
        }
    }

    private function row(): array
    {
        if ($this->cachedRow !== null) {
            return $this->cachedRow;
        }

        try {
            $row = DB::table(self::TABLE)->first();

            return $this->cachedRow = $row ? (array) $row : [];
        } catch (Throwable) {
            return $this->cachedRow = [];
        }
    }

    private function readFile(): ?array
    {
        if (! is_readable($this->path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($this->path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
