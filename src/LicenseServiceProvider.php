<?php

declare(strict_types=1);

namespace Mazaya\License;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Mazaya\License\Console\ActivateCommand;
use Mazaya\License\Console\DoctorCommand;
use Mazaya\License\Console\HeartbeatCommand;
use Mazaya\License\Console\OfflineApplyCommand;
use Mazaya\License\Console\InstallSchedulerCommand;
use Mazaya\License\Console\OfflineRequestCommand;
use Mazaya\License\Console\ResealCommand;
use Mazaya\License\Console\StatusCommand;
use Mazaya\License\Fingerprint\Collector;
use Mazaya\License\Guard\Gate;
use Mazaya\License\Guard\Integrity;
use Mazaya\License\Guard\Manifest;
use Mazaya\License\Guard\Seal;
use Mazaya\License\Http\Middleware\EnforceLicense;
use Mazaya\License\Http\Middleware\HaltIfUnlicensed;
use Mazaya\License\Http\Middleware\InjectLicenseNotice;
use Mazaya\License\Http\Middleware\RenewLicense;
use Mazaya\License\Storage\ClockGuard;
use Mazaya\License\Storage\StateStore;
use Mazaya\License\Token\Verifier;
use Mazaya\License\Transport\LicenseServerClient;

class LicenseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/license.php', 'license');

        $this->app->singleton(StateStore::class, fn ($app): StateStore => new StateStore(
            $app['config']->get('license.state_path') ?? storage_path('app/license/license.lic'),
        ));

        $this->app->singleton(Verifier::class, fn ($app): Verifier => new Verifier(
            array_filter((array) $app['config']->get('license.keys', [])),
        ));

        $this->app->singleton(ClockGuard::class, fn ($app): ClockGuard => new ClockGuard(
            $app->make(StateStore::class),
            (int) $app['config']->get('license.clock_tolerance', 3600),
        ));

        $this->app->singleton(Collector::class);

        $this->app->singleton(Integrity::class, fn (): Integrity => new Integrity(dirname(__DIR__)));

        $this->app->singleton(Manifest::class, fn ($app): Manifest => new Manifest(
            $app->make(Verifier::class),
            $app->make(Integrity::class),
            $app->make(Config::class),
            dirname(__DIR__),
        ));

        $this->app->singleton(Seal::class, fn ($app): Seal => new Seal(
            $app->make(Config::class),
            $app->make(Integrity::class),
        ));

        $this->app->singleton(Gate::class, fn ($app): Gate => new Gate(
            $app->make(StateStore::class),
            $app->make(Verifier::class),
            $app->make(ClockGuard::class),
            $app->make(Collector::class),
            $app->make(Seal::class),
            $app->make(Cache::class),
            $app->make(Config::class),
            $app->make(Manifest::class),
        ));

        $this->app->singleton(LicenseServerClient::class, fn ($app): LicenseServerClient => new LicenseServerClient(
            $app->make(Http::class),
            $app->make(Config::class),
        ));

        $this->app->singleton(LicenseManager::class, fn ($app): LicenseManager => new LicenseManager(
            $app->make(Gate::class),
            $app->make(Seal::class),
            $app->make(Integrity::class),
            $app->make(StateStore::class),
            $app->make(Verifier::class),
            $app->make(Collector::class),
            $app->make(ClockGuard::class),
            $app->make(LicenseServerClient::class),
            $app->make(Config::class),
        ));
    }

    public function boot(Router $router): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'license');

        $router->aliasMiddleware('license', EnforceLicense::class);

        $this->enforceGlobally();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ActivateCommand::class,
                HeartbeatCommand::class,
                StatusCommand::class,
                OfflineRequestCommand::class,
                OfflineApplyCommand::class,
                ResealCommand::class,
                InstallSchedulerCommand::class,
                DoctorCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/license.php' => config_path('license.php'),
            ], 'license-config');

            $this->scheduleHeartbeat();
        }
    }

    /**
     * Attach enforcement to everything, from inside the package.
     *
     * The route-level `license` middleware is opt-in and therefore opt-out —
     * whoever attaches it can detach it. This one is prepended to the global
     * stack by the package itself, so the only way past it is to remove the
     * package, which stops the application booting at all.
     */
    private function enforceGlobally(): void
    {
        // Registered unconditionally. Gating registration on the configured
        // mode would put the decision back in the file being tampered with;
        // the guard itself asks the gate and a genuine hosted install falls
        // through it in microseconds.
        if (! $this->app->runningInConsole()) {
            $this->app->booted(function (): void {
                $kernel = $this->app->make(Kernel::class);

                $kernel->prependMiddleware(HaltIfUnlicensed::class);

                // Renewal runs in terminate(), after the response is flushed,
                // so an install that serves traffic stays licensed even when
                // nobody set up cron.
                $kernel->prependMiddleware(RenewLicense::class);

                // Appended, not prepended: it needs the finished page to inject into.
                $kernel->pushMiddleware(InjectLicenseNotice::class);
            });

            return;
        }

        $this->guardConsole();
    }

    /**
     * Console commands are gated too, or an unlicensed system is still fully
     * operable through artisan and the queue.
     *
     * The exceptions are the commands needed to *fix* a licensing problem. Omit
     * them and a customer whose licence has genuinely lapsed has no way back —
     * which would be a support disaster, not a stronger licence.
     */
    private function guardConsole(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $command = (string) $event->command;

            if ($command === '' || $this->isRecoveryCommand($command)) {
                return;
            }

            $gate = $this->app->make(Gate::class);

            if (! $gate->isEnforcing() || $gate->state()->isUsable()) {
                return;
            }

            $event->output->writeln('<error>This system is not licensed. Run: php artisan license:status</error>');

            exit(1);
        });
    }

    private function isRecoveryCommand(string $command): bool
    {
        foreach ([
            'license:', 'migrate', 'config:', 'cache:', 'optimize', 'view:', 'route:', 'event:',
            'package:discover', 'vendor:publish', 'storage:link', 'key:generate',
            'about', 'list', 'help', 'env', 'schedule:run', 'schedule:work', 'down', 'up',

            // `serve` hosts the application; it does not operate it. Blocking it
            // stopped the web server from starting at all, so a customer whose
            // licence had lapsed could not even reach the page explaining why.
            // The HTTP guard is what enforces there, and it does so gracefully.
            'serve',
        ] as $prefix) {
            if ($command === $prefix || str_starts_with($command, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The renewal loop. It runs far more often than the token's lifetime, so a
     * long outage costs the customer nothing — the TTL, not the schedule, is
     * what keeps them running.
     */
    private function scheduleHeartbeat(): void
    {
        if ($this->app['config']->get('license.mode') !== 'onprem') {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Runs every minute, acts almost never.
            //
            // The command decides whether a heartbeat is due, from the interval
            // the licence server last asked for. Anything less frequent than
            // this becomes a floor the server cannot ask below: scheduling it
            // every five minutes silently capped a two-minute check-in window at
            // five, so an installation could not comply with what it had been
            // told. The server already refuses to ask for less than sixty
            // seconds, so a minute is the right cadence.
            //
            // In normal operation this wakes, finds nothing due, and exits —
            // the same cost as any other per-minute scheduled task.
            $schedule->command('license:heartbeat --quiet-fail --if-due')
                ->everyMinute()
                ->withoutOverlapping()
                ->runInBackground();
        });
    }
}
