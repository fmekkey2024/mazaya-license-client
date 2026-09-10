<?php

declare(strict_types=1);

namespace Mazaya\License\Console;

use Illuminate\Console\Command;
use Mazaya\License\LicenseManager;

class StatusCommand extends Command
{
    protected $signature = 'license:status {--json}';

    protected $description = 'Show this installation\'s license status';

    public function handle(LicenseManager $license): int
    {
        $state   = $license->state();
        $payload = $license->payload();

        $summary = [
            'mode'           => (string) config('license.mode'),
            'product'        => (string) config('license.product'),
            'state'          => $state->value,
            'install_id'     => $license->installId(),
            'customer'       => $payload['customer'] ?? null,
            'expires_at'     => $license->expiresAt() ? date('Y-m-d H:i:s', $license->expiresAt()) : null,
            'days_remaining' => $license->daysRemaining(),
            'grace_days'     => $payload['grace_days'] ?? null,
            'seq'            => $payload['seq'] ?? null,
            'last_heartbeat' => $license->lastHeartbeatAt(),
            'allows_writes'  => $license->allowsWrites(),
            'allows_reads'   => $license->allowsReads(),
            'message'        => $license->message(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $state->isUsable() ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->line('  <options=bold>'.$state->headline().'</>');
        $this->newLine();

        foreach ($summary as $label => $value) {
            $this->line(sprintf(
                '  %-16s %s',
                $label,
                match (true) {
                    is_bool($value) => $value ? 'yes' : 'no',
                    $value === null => '—',
                    default         => (string) $value,
                },
            ));
        }

        $this->warnIfSchedulerIsNotRunning($license);

        $this->newLine();

        return $state->isUsable() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The single most common way an on-premise install dies quietly.
     *
     * The package schedules its own heartbeat, but Laravel's scheduler only
     * runs if the operating system is calling `schedule:run` every minute. Miss
     * that one cron line and nothing renews: everything looks healthy for weeks
     * and then the licence lapses for no visible reason.
     */
    private function warnIfSchedulerIsNotRunning(LicenseManager $license): void
    {
        $last = $license->lastHeartbeatAt();

        if ($license->installId() === null || $last === null) {
            return;   // never activated; nothing to renew yet
        }

        $interval = max(1, (int) config('license.heartbeat_hours', 6));
        $stale    = strtotime($last) < time() - ($interval * 2 * 3600);

        if (! $stale) {
            return;
        }

        $this->newLine();
        $this->warn('  The last heartbeat is older than two scheduled runs.');
        $this->line('  Either this server cannot reach the licence service, or the scheduler is not running.');
        $this->line('  The scheduler needs one line in cron:');
        $this->newLine();
        $this->line('    * * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1');
    }
}
