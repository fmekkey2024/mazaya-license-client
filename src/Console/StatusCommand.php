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

        $this->newLine();

        return $state->isUsable() ? self::SUCCESS : self::FAILURE;
    }
}
