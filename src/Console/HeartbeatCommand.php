<?php

declare(strict_types=1);

namespace Mazaya\License\Console;

use Illuminate\Console\Command;
use Mazaya\License\LicenseManager;
use Throwable;

class HeartbeatCommand extends Command
{
    protected $signature = 'license:heartbeat {--quiet-fail : Exit 0 even if the server is unreachable}';

    protected $description = 'Renew this installation\'s license';

    public function handle(LicenseManager $license): int
    {
        try {
            $response = $license->refresh();
        } catch (Throwable $exception) {
            // An unreachable server is an ordinary condition on an isolated
            // network, not an incident: the current token covers the outage.
            // Only the scheduled run treats it as non-fatal, so an operator
            // running this by hand still sees a real exit code.
            $this->warn('Could not reach the license server: '.$exception->getMessage());
            $this->line('The current license remains in force until it expires.');

            return $this->option('quiet-fail') ? self::SUCCESS : self::FAILURE;
        }

        $status = $response['status'] ?? 'unknown';

        if ($status === 'active') {
            $this->info('License renewed — expires '.date('Y-m-d H:i', (int) ($license->expiresAt() ?? 0)).'.');

            return self::SUCCESS;
        }

        $this->warn("License server reports: {$status}");

        if (! empty($response['message'])) {
            $this->line('  '.$response['message']);
        }

        return self::SUCCESS;
    }
}
