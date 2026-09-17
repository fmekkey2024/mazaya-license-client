<?php

declare(strict_types=1);

namespace Mazaya\License\Console;

use Illuminate\Console\Command;
use Mazaya\License\LicenseManager;
use Throwable;

class ActivateCommand extends Command
{
    protected $signature = 'license:activate {key? : The license key supplied by your vendor}
                                             {--label= : production, staging or dr}';

    protected $description = 'Activate this installation against the license server';

    public function handle(LicenseManager $license): int
    {
        $key = $this->argument('key') ?: config('license.key');

        if (! $key) {
            $this->error('No license key given. Pass it as an argument or set LICENSE_KEY.');

            return self::FAILURE;
        }

        try {
            $response = $license->activate($key, ['label' => $this->option('label')]);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Activated.');
        $this->line("  install id : {$response['install_id']}");
        $this->line('  expires    : '.date('Y-m-d H:i', (int) ($license->expiresAt() ?? 0)));

        $this->newLine();
        $this->line('Setting up automatic renewal...');

        // Best-effort: install the scheduler cron so the licence renews on its
        // own. Writing /etc/cron.d needs root, so a non-root activation still
        // succeeds -- it just cannot finish this one step itself, and says so.
        if ($this->call('license:install-scheduler') !== self::SUCCESS) {
            $this->newLine();
            $this->line('That step needs root: re-run this as root (or with sudo) to finish it, or');
            $this->line('add the cron line shown above. Until then, ordinary web traffic still');
            $this->line('renews the licence on its own -- any request does it.');
        }


        $this->newLine();
        $this->line('Setting up instant updates (optional)...');
        // Best-effort and non-fatal: instant push is a nice-to-have on top of
        // the heartbeat. Needs root to install the service; prints how otherwise.
        $this->call('license:install-listener');

        return self::SUCCESS;
    }
}
