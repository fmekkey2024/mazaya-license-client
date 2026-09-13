<?php

declare(strict_types=1);

namespace Mazaya\License\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Mazaya\License\LicenseManager;
use Throwable;

/**
 * Re-bind the licence to the current code and configuration, forcefully.
 *
 * The seal covers the whole application's source in strict mode, so after any
 * deploy or `composer update` the fingerprint has moved and the system will
 * refuse to run until it is resealed. This command is the one step a deploy
 * script needs: it flushes every stale cache first — because a cached config or
 * a compiled route from before the deploy would otherwise be sealed in, or make
 * the seal itself read a stale value — reseals against the fresh code, then
 * rebuilds the caches so the app is warm again.
 */
class ResealCommand extends Command
{
    protected $signature = 'license:reseal {--no-cache : Reseal only; do not flush or rebuild caches}';

    protected $description = 'Re-bind the licence to the current code and configuration after a deploy';

    public function handle(LicenseManager $license): int
    {
        if (! $this->option('no-cache')) {
            // Clear everything that could hold a pre-deploy view of the code,
            // so the reseal reads the code that is actually on disk now.
            foreach (['optimize:clear', 'config:clear', 'route:clear', 'view:clear', 'event:clear', 'cache:clear'] as $command) {
                $this->runQuietly($command);
            }
        }

        if (! $license->reseal()) {
            $this->error('This installation has not been activated, so there is nothing to seal.');

            return self::FAILURE;
        }

        $this->info('Configuration re-sealed against the current code.');

        if (! $this->option('no-cache')) {
            // Warm the app back up. Config and events are safe to cache
            // everywhere; routes and views are too for the vast majority of
            // apps. Any failure here is non-fatal — an uncached app still runs.
            foreach (['config:cache', 'event:cache', 'route:cache', 'view:cache'] as $command) {
                $this->runQuietly($command);
            }

            $this->line('  Caches flushed and rebuilt.');
        }

        // Prove it took, rather than leaving the operator to guess.
        $this->call('license:status');

        return self::SUCCESS;
    }

    private function runQuietly(string $command): void
    {
        try {
            Artisan::call($command);
        } catch (Throwable) {
            // route:cache fails on apps with closure routes, view:cache on some
            // setups — none of it should stop a reseal from succeeding.
        }
    }
}
