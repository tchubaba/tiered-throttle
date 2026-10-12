<?php

declare(strict_types=1);

namespace Tchubaba\TieredThrottle\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tchubaba\TieredThrottle\Support\ThrottleBypass;
use Tchubaba\TieredThrottle\Tests\TestCase;

/**
 * The bypass levers, and above all the environment gate that decides whether
 * they may take effect at all.
 */
class BypassTest extends TestCase
{
    private const IP       = '1.2.3.4';
    private const OTHER_IP = '9.8.7.6';
    private const LOCAL_IP = '127.0.0.1';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/test-route', function () {
            return response()->json(['message' => 'success']);
        })->middleware('tiered.throttle:test-limiter');

        Route::get('/unknown-limiter-route', function () {
            return response()->json(['message' => 'success']);
        })->middleware('tiered.throttle:not-a-configured-limiter');

        Cache::flush();

        // The once-per-process log dedupe flags outlive a single test.
        ThrottleBypass::resetLoggingState();
    }

    /**
     * @return list<\Illuminate\Testing\TestResponse>
     */
    private function hit(int $n, string $ip = self::IP, string $route = '/test-route'): array
    {
        $responses = [];

        for ($i = 0; $i < $n; $i++) {
            $responses[] = $this
                ->withServerVariables(['REMOTE_ADDR' => $ip])
                ->getJson($route);
        }

        return $responses;
    }

    private function lastStatus(array $responses): int
    {
        return end($responses)->status();
    }

    private function assertNoneThrottled(array $responses): void
    {
        foreach ($responses as $i => $response) {
            $this->assertNotEquals(429, $response->status(), 'Request #'.($i + 1).' unexpectedly returned 429');
        }
    }

    private function setEnvironment(string $environment): void
    {
        $this->app['env'] = $environment;
    }

    public function test_it_throttles_by_default_in_the_test_environment(): void
    {
        $this->assertSame('testing', $this->app['env']);

        $this->assertEquals(429, $this->lastStatus($this->hit(11)));
    }

    public function test_it_skips_throttling_when_disabled_in_an_allowlisted_environment(): void
    {
        config()->set('tiered-throttle.enabled', false);

        $this->assertNoneThrottled($this->hit(20));
    }

    /**
     * The guard that matters: the kill switch must not be able to turn off
     * protection on a production host.
     */
    public function test_it_still_throttles_when_disabled_but_the_environment_is_production(): void
    {
        config()->set('tiered-throttle.enabled', false);
        $this->setEnvironment('production');

        $this->assertEquals(429, $this->lastStatus($this->hit(11)));
    }

    /**
     * Fail closed: an environment nobody recognises keeps the limiter on, which
     * is what a "not production" denylist would have got wrong.
     */
    public function test_it_still_throttles_when_disabled_in_an_unrecognised_environment(): void
    {
        config()->set('tiered-throttle.enabled', false);
        $this->setEnvironment('staging');

        $this->assertEquals(429, $this->lastStatus($this->hit(11)));
    }

    public function test_it_still_throttles_when_disabled_in_a_typo_of_production(): void
    {
        config()->set('tiered-throttle.enabled', false);
        $this->setEnvironment('prod');

        $this->assertEquals(429, $this->lastStatus($this->hit(11)));
    }

    public function test_it_honours_a_custom_bypass_environment_allowlist(): void
    {
        config()->set('tiered-throttle.enabled', false);
        config()->set('tiered-throttle.bypass_environments', ['staging']);
        $this->setEnvironment('staging');

        $this->assertNoneThrottled($this->hit(20));
    }

    public function test_it_accepts_a_string_false_from_the_environment_file(): void
    {
        // .env values arrive as strings, so "false" must mean the same as false.
        config()->set('tiered-throttle.enabled', 'false');

        $this->assertNoneThrottled($this->hit(20));
    }

    public function test_the_ip_allowlist_exempts_only_the_named_ip(): void
    {
        config()->set('tiered-throttle.bypass_ips', [self::IP]);

        $this->assertNoneThrottled($this->hit(20, self::IP));
        $this->assertEquals(429, $this->lastStatus($this->hit(11, self::OTHER_IP)));
    }

    /**
     * Committing bypass_ips must not be able to exempt an address on a live host.
     */
    public function test_the_ip_allowlist_is_inert_outside_the_bypass_environments(): void
    {
        config()->set('tiered-throttle.bypass_ips', [self::IP]);
        $this->setEnvironment('production');

        $this->assertEquals(429, $this->lastStatus($this->hit(11, self::IP)));
    }

    public function test_it_throttles_private_addresses_by_default(): void
    {
        $this->assertEquals(429, $this->lastStatus($this->hit(11, self::LOCAL_IP)));
    }

    public function test_it_exempts_private_ranges_when_that_option_is_enabled(): void
    {
        config()->set('tiered-throttle.bypass_private_ranges', true);

        // 172.17.0.1 is the Docker bridge gateway a container sees for the host.
        $this->assertNoneThrottled($this->hit(20, self::LOCAL_IP));
        $this->assertNoneThrottled($this->hit(20, '172.17.0.1'));
        $this->assertNoneThrottled($this->hit(20, '10.0.4.9'));
    }

    public function test_private_ranges_still_throttle_public_addresses(): void
    {
        config()->set('tiered-throttle.bypass_private_ranges', true);

        $this->assertEquals(429, $this->lastStatus($this->hit(11, self::IP)));
    }

    public function test_it_logs_an_error_when_the_kill_switch_is_refused(): void
    {
        Log::spy();

        config()->set('tiered-throttle.enabled', false);
        $this->setEnvironment('production');

        $this->assertEquals(429, $this->lastStatus($this->hit(11)));

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'bypass_environments'));
    }

    public function test_it_throws_for_an_unknown_limiter_name_outside_production(): void
    {
        $this->withoutExceptionHandling();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no limiter named [not-a-configured-limiter] is configured');

        $this->hit(1, self::IP, '/unknown-limiter-route');
    }

    public function test_it_allows_an_unknown_limiter_name_in_production_and_logs_it(): void
    {
        Log::spy();
        $this->setEnvironment('production');

        $response = $this->hit(1, self::IP, '/unknown-limiter-route');

        $this->assertEquals(200, $this->lastStatus($response));

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'not-a-configured-limiter'));
    }
}
