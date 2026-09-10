<?php

declare(strict_types=1);

namespace Mazaya\License\Console;

use Illuminate\Console\Command;
use Mazaya\License\LicenseManager;

class ResealCommand extends Command
{
    protected $signature = 'license:reseal';

    protected $description = 'Re-bind the license to the current configuration after a deliberate change';

    public function handle(LicenseManager $license): int
    {
        $this->warn('This adopts the current licensing configuration as the sealed one.');
        $this->line('Only run it after a change you made on purpose — a new license server');
        $this->line('address, or a rotated signing key.');

        if (! $license->reseal()) {
            $this->error('This installation has not been activated, so there is nothing to seal.');

            return self::FAILURE;
        }

        $this->info('Configuration re-sealed.');

        return self::SUCCESS;
    }
}
