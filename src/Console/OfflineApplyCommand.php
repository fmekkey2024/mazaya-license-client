<?php

declare(strict_types=1);

namespace Mazaya\License\Console;

use Illuminate\Console\Command;
use Mazaya\License\LicenseManager;
use Throwable;

class OfflineApplyCommand extends Command
{
    protected $signature = 'license:offline-apply {file : The .lic file supplied by your vendor}';

    protected $description = 'Apply a license issued offline';

    public function handle(LicenseManager $license): int
    {
        $file = $this->argument('file');

        if (! is_readable($file)) {
            $this->error("Cannot read {$file}.");

            return self::FAILURE;
        }

        try {
            $payload = $license->offlineApply((string) file_get_contents($file));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('License applied — expires '.date('Y-m-d H:i', (int) $payload['exp']).'.');

        return self::SUCCESS;
    }
}
