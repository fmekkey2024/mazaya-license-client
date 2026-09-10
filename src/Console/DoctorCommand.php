<?php

declare(strict_types=1);

namespace Mazaya\License\Console;

use Illuminate\Console\Command;
use Mazaya\License\Enums\LicenseState;
use Mazaya\License\Guard\Gate;
use Mazaya\License\LicenseServiceProvider;
use Mazaya\License\LicenseManager;
use Mazaya\License\Storage\StateStore;
use Throwable;

/**
 * Checks that an installation was set up correctly, in one command.
 *
 * "Follow the instructions" is not verifiable, and the ways an install goes
 * wrong are all silent: the wrong public key, a forgotten activation, a
 * scheduler nobody set up, a firewall that was never opened. Every one of them
 * looks fine on the day and fails weeks later at the customer's site.
 *
 * Run it at the end of an install, and again whenever something looks odd.
 */
class DoctorCommand extends Command
{
    protected $signature = 'license:doctor';

    protected $description = 'Check that this installation is correctly licensed and will stay that way';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(LicenseManager $license, Gate $gate, StateStore $store): int
    {
        $this->newLine();
        $this->line('  <options=bold>Licensing check</>');
        $this->newLine();

        $this->section('Environment');
        $this->check('PHP has ext-sodium', extension_loaded('sodium'), 'signatures cannot be verified without it');
        $this->check('PHP 8.2 or newer', PHP_VERSION_ID >= 80200, 'running '.PHP_VERSION);
        $this->check('Licence storage is writable', $this->storageWritable(),
            'storage/app/license must be writable, or only the database copy survives');

        $this->section('Configuration');
        $mode = (string) config('license.mode');
        $this->check("Mode is 'onprem' (is '{$mode}')", $mode === 'onprem',
            'a hosted install enforces nothing; set LICENSE_MODE=onprem');
        $this->check('Product is set', ((string) config('license.product')) !== 'unset',
            'LICENSE_PRODUCT must match the product slug in the panel');
        $keys = array_filter((array) config('license.keys', []));
        $this->check('A signing key is configured', $keys !== [],
            'LICENSE_KID and LICENSE_PUBLIC_KEY come from the product screen in the panel');
        $this->check('Server address is set', ((string) config('license.server')) !== '', 'LICENSE_SERVER is empty');

        $this->section('Activation');
        $activated = $store->installId() !== null;
        $this->check('This installation is activated', $activated, 'run: php artisan license:activate <key>');

        $state = $gate->state();
        $this->check(
            "Licence state is usable (is '{$state->value}')",
            $state->isUsable(),
            $state === LicenseState::Tampered
                ? 'the configuration changed since activation — run license:reseal if that was deliberate'
                : 'see: php artisan license:status'
        );

        if ($activated) {
            // Compared against the renewal interval, not a fixed number of days.
            //
            // What this reports is the life of the current *token*, which is
            // capped by the licence's TTL — an installation on a 5-day TTL never
            // has more than 5 days on it and is perfectly healthy. Comparing
            // that to a fixed week reported a problem on every short-TTL licence
            // and none of them were real.
            //
            // The client cannot see the subscription end date at all; the
            // server caps each token at it. So the only thing worth asking here
            // is whether the token outlasts the next couple of renewal attempts.
            $days     = $license->daysRemaining();
            $interval = max(1, (int) config('license.heartbeat_hours', 6));
            $needed   = ($interval * 2) / 24;

            $this->check(
                'The current token outlasts the next renewals'
                    .($days === null ? '' : " ({$days}d left, renews every {$interval}h)"),
                $days !== null && $days >= $needed,
                'renewal is not keeping up — the licence may be suspended, or the server unreachable'
            );
        }

        $this->section('Enforcement');
        // Checked by asking whether the package is loaded, not by inspecting
        // the HTTP kernel: the web guard is only prepended on web requests, so
        // looking for it from a console command always finds nothing. If the
        // provider is registered, both guards are in place by construction.
        $this->check('The package is loaded, so every request is guarded', $this->packageLoaded(),
            'the service provider is not registered — check composer autoload and dont-discover');

        $this->section('Renewal');
        $this->check('The licence service is reachable', $this->serverReachable(),
            'open outbound HTTPS to '.config('license.server').' — this is the one firewall rule needed');

        $last = $store->lastHeartbeatAt();
        $interval = max(1, (int) config('license.heartbeat_hours', 6));
        $fresh = $last !== null && strtotime($last) > time() - ($interval * 2 * 3600);
        $this->check('A heartbeat has run recently'.($last === null ? '' : " (last: {$last})"), $fresh,
            'run: php artisan license:install-scheduler');

        $this->check('Renewal from web traffic is enabled', (bool) config('license.renew_on_request', true),
            'without it, a missing scheduler means the licence never renews', warnOnly: true);

        $this->newLine();

        if ($this->failures > 0) {
            $this->error("  {$this->failures} problem(s) need fixing before this installation is handed over.");

            return self::FAILURE;
        }

        if ($this->warnings > 0) {
            $this->warn("  Ready, with {$this->warnings} thing(s) worth a look.");

            return self::SUCCESS;
        }

        $this->info('  This installation is correctly licensed and will renew on its own.');

        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->line("  <fg=gray>{$title}</>");
    }

    private function check(string $label, bool $passed, string $remedy, bool $warnOnly = false): void
    {
        if ($passed) {
            $this->line("    <fg=green>✓</> {$label}");

            return;
        }

        if ($warnOnly) {
            $this->warnings++;
            $this->line("    <fg=yellow>!</> {$label}");
        } else {
            $this->failures++;
            $this->line("    <fg=red>✗</> {$label}");
        }

        $this->line("      <fg=gray>{$remedy}</>");
    }

    private function storageWritable(): bool
    {
        $dir = storage_path('app/license');

        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        return is_dir($dir) && is_writable($dir);
    }

    private function packageLoaded(): bool
    {
        return array_key_exists(LicenseServiceProvider::class, app()->getLoadedProviders());
    }

    private function serverReachable(): bool
    {
        try {
            $response = \Illuminate\Support\Facades\Http::withOptions(
                array_filter(['proxy' => config('license.proxy')])
            )->timeout(8)->get(rtrim((string) config('license.server'), '/').'/up');

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
