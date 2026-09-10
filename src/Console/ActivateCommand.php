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

        return self::SUCCESS;
    }
}
