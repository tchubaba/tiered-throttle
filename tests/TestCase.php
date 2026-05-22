<?php

declare(strict_types=1);

namespace Tchubaba\TieredThrottle\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Tchubaba\TieredThrottle\TieredThrottleServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            TieredThrottleServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Setup default config for testing
        $app['config']->set('tiered-throttle.limiters.test-limiter', [
            'tiers' => [
                [10, 60],
                [10, 120],
                [5, 300],
            ],
            'lockout_seconds' => 43200,
            'offense_ttl'     => 86400,
        ]);

        $app['config']->set('tiered-throttle.limiters.other-limiter', [
            'tiers' => [
                [10, 60],
                [5, 300],
            ],
            'lockout_seconds' => 43200,
            'offense_ttl'     => 86400,
        ]);
    }
}
