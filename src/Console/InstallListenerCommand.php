<?php

declare(strict_types=1);

namespace Mazaya\License\Console;

use Illuminate\Console\Command;

/**
 * Installs the systemd service that keeps `license:listen` running, so panel
 * decisions reach this installation in seconds (instant push) rather than at
 * the next poll.
 *
 * Optional by design: the scheduled heartbeat is the baseline and always works.
 * This just adds the low-latency channel. Like the scheduler it needs root to
 * write a unit file, so a non-root run prints the unit and how to enable it.
 */
class InstallListenerCommand extends Command
{
    protected $signature = 'license:install-listener {--user= : System user the application runs as}
                                                     {--print : Only show the unit, install nothing}';

    protected $description = 'Install the service that holds the instant-update (SSE) connection';

    public function handle(): int
    {
        $path = base_path();
        $php  = PHP_BINARY;
        $user = $this->option('user') ?: $this->appUser();
        $name = $this->serviceName();

        $unit = <<<UNIT
        [Unit]
        Description=Mazaya License listener ({$name}) — instant updates
        After=network.target

        [Service]
        User={$user}
        WorkingDirectory={$path}
        ExecStart={$php} {$path}/artisan license:listen
        Restart=always
        RestartSec=5

        [Install]
        WantedBy=multi-user.target
        UNIT;
        $unit = preg_replace('/^        /m', '', $unit)."\n";

        if ($this->option('print')) {
            $this->line($unit);

            return self::SUCCESS;
        }

        if (! $this->runningAsRoot()) {
            $this->warn('Not running as root, so the listener service was not installed.');
            $this->newLine();
            $this->line("Instant updates are optional — the scheduled heartbeat already keeps this");
            $this->line("installation current. To add the low-latency channel, save this as");
            $this->line("/etc/systemd/system/{$name}.service and enable it:");
            $this->newLine();
            $this->line($unit);
            $this->line("  systemctl daemon-reload && systemctl enable --now {$name}");

            return self::FAILURE;
        }

        $target = "/etc/systemd/system/{$name}.service";

        if (@file_put_contents($target, $unit) === false) {
            $this->error("Could not write {$target}.");

            return self::FAILURE;
        }

        @exec('systemctl daemon-reload 2>/dev/null');
        @exec('systemctl enable --now '.escapeshellarg($name).' 2>/dev/null', $out, $code);

        if ($code !== 0) {
            $this->warn("Wrote {$target}, but could not start it automatically.");
            $this->line("  systemctl enable --now {$name}");

            return self::FAILURE;
        }

        $this->info("Installed and started {$target}");
        $this->line('  Panel decisions now reach this installation in seconds.');

        return self::SUCCESS;
    }

    private function serviceName(): string
    {
        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) config('app.name', 'app')));

        return trim((string) $slug, '-').'-license-listener';
    }

    /** The user the service runs as — the owner of the app dir under sudo, not root. */
    private function appUser(): string
    {
        if ($this->runningAsRoot() && function_exists('posix_getpwuid')) {
            $owner = @fileowner(base_path());

            if ($owner !== false) {
                $info = posix_getpwuid($owner);

                if (is_array($info) && isset($info['name']) && $info['name'] !== 'root') {
                    return (string) $info['name'];
                }
            }
        }

        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = posix_getpwuid(posix_geteuid());

            if (is_array($info) && isset($info['name'])) {
                return (string) $info['name'];
            }
        }

        return (string) (get_current_user() ?: 'www-data');
    }

    private function runningAsRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }
}
