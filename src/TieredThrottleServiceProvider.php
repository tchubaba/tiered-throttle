<?php

declare(strict_types=1);

namespace Tchubaba\TieredThrottle;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Tchubaba\TieredThrottle\Console\Commands\Status;
use Tchubaba\TieredThrottle\Console\Commands\UnbanIp;
use Tchubaba\TieredThrottle\Http\Middleware\TieredThrottle;
use Tchubaba\TieredThrottle\Support\DecisionRepository;
use Tchubaba\TieredThrottle\Support\ThrottleBypass;

class TieredThrottleServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/tiered-throttle.php',
            'tiered-throttle'
        );

        // Singletons so the middleware, the commands and any host code all read
        // and clear the same set of cache keys.
        $this->app->singleton(DecisionRepository::class);
        $this->app->singleton(ThrottleBypass::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(Router $router): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/tiered-throttle.php' => config_path('tiered-throttle.php'),
            ], 'tiered-throttle-config');

            $this->commands([
                UnbanIp::class,
                Status::class,
            ]);
        }

        $router->aliasMiddleware('tiered.throttle', TieredThrottle::class);
    }
}
