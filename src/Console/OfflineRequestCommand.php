<?php

declare(strict_types=1);

namespace Mazaya\License\Console;

use Illuminate\Console\Command;
use Mazaya\License\LicenseManager;

class OfflineRequestCommand extends Command
{
    protected $signature = 'license:offline-request {--output= : Where to write the .lreq file}';

    protected $description = 'Produce a request file for offline license issuance';

    public function handle(LicenseManager $license): int
    {
        $path = $this->option('output') ?: storage_path('app/license/request-'.date('Ymd-His').'.lreq');

        @mkdir(dirname($path), 0750, true);
        file_put_contents($path, $license->offlineRequest());

        $this->info('Request file written.');
        $this->line("  {$path}");
        $this->newLine();
        $this->line('Send this file to your vendor, then apply the license they return with:');
        $this->line('  php artisan license:offline-apply <file>');

        return self::SUCCESS;
    }
}
