<?php

declare(strict_types=1);

namespace Mazaya\License;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Mazaya\License\Console\ActivateCommand;
use Mazaya\License\Console\HeartbeatCommand;
use Mazaya\License\Console\OfflineApplyCommand;
use Mazaya\License\Console\OfflineRequestCommand;
use Mazaya\License\Console\StatusCommand;
use Mazaya\License\Fingerprint\Collector;
use Mazaya\License\Guard\Gate;
use Mazaya\License\Http\Middleware\EnforceLicense;
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

        $this->app->singleton(Gate::class, fn ($app): Gate => new Gate(
            $app->make(StateStore::class),
            $app->make(Verifier::class),
            $app->make(ClockGuard::class),
            $app->make(Collector::class),
            $app->make(Cache::class),
            $app->make(Config::class),
        ));

        $this->app->singleton(LicenseServerClient::class, fn ($app): LicenseServerClient => new LicenseServerClient(
            $app->make(Http::class),
            $app->make(Config::class),
        ));

        $this->app->singleton(LicenseManager::class, fn ($app): LicenseManager => new LicenseManager(
            $app->make(Gate::class),
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

        if ($this->app->runningInConsole()) {
            $this->commands([
                ActivateCommand::class,
                HeartbeatCommand::class,
                StatusCommand::class,
                OfflineRequestCommand::class,
                OfflineApplyCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/license.php' => config_path('license.php'),
            ], 'license-config');

            $this->scheduleHeartbeat();
        }
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
            $hours = max(1, (int) $this->app['config']->get('license.heartbeat_hours', 6));

            $schedule->command('license:heartbeat --quiet-fail')
                ->cron('17 */'.$hours.' * * *')  // offset so fleets do not all call on the hour
                ->withoutOverlapping()
                ->runInBackground();
        });
    }
}
