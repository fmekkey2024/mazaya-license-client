<?php

declare(strict_types=1);

namespace Mazaya\License\Console;

use Illuminate\Console\Command;

/**
 * Installs the one cron line Laravel's scheduler needs.
 *
 * Written as a command rather than a documentation step because a step in a
 * document is a step somebody skips, and skipping this one is invisible until
 * the licence lapses weeks later.
 */
class InstallSchedulerCommand extends Command
{
    protected $signature = 'license:install-scheduler {--user= : System user the application runs as}
                                                      {--print : Only show the line, install nothing}';

    protected $description = 'Install the cron entry that runs Laravel\'s scheduler';

    public function handle(): int
    {
        $path = base_path();
        $php  = PHP_BINARY;
        $user = $this->option('user') ?: $this->appUser();
        $line = "* * * * * {$user} cd {$path} && {$php} artisan schedule:run >> /dev/null 2>&1";

        if ($this->option('print')) {
            $this->line($line);

            return self::SUCCESS;
        }

        if (! $this->runningAsRoot()) {
            $this->warn('Not running as root, so nothing was installed.');
            $this->newLine();
            $this->line('Add this to the crontab of the user the application runs as:');
            $this->newLine();
            $this->line("  * * * * * cd {$path} && {$php} artisan schedule:run >> /dev/null 2>&1");
            $this->newLine();
            $this->line('  crontab -e -u '.$user);

            return self::FAILURE;
        }

        $target = '/etc/cron.d/'.$this->cronName();

        if (@file_put_contents($target, $line."\n") === false) {
            $this->error("Could not write {$target}.");

            return self::FAILURE;
        }

        @chmod($target, 0644);

        $this->info("Installed {$target}");
        $this->line('  '.$line);
        $this->newLine();
        $this->line('The licence now renews on its own. Confirm with: php artisan license:status');

        return self::SUCCESS;
    }

    private function cronName(): string
    {
        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) config('app.name', 'app')));

        return trim((string) $slug, '-').'-scheduler';
    }

    /**
     * The user the scheduler line should run as.
     *
     * Under sudo the process is root, but the cron must run as the user that
     * owns the application -- otherwise schedule:run writes caches and logs as
     * root and the app can no longer read them. The owner of the application
     * directory is that user.
     */
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

        return $this->currentUser();
    }

    private function currentUser(): string
    {
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
