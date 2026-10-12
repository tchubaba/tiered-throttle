<?php

declare(strict_types=1);

namespace Tchubaba\TieredThrottle\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tchubaba\TieredThrottle\Support\ThrottleBypass;
use Tchubaba\TieredThrottle\Tests\TestCase;

/**
 * throttle:unban has to say what it actually found, rather than reporting a
 * successful unban for an IP that was never throttled.
 */
class UnbanCommandTest extends TestCase
{
    private const IP       = '1.2.3.4';
    private const OTHER_IP = '9.8.7.6';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/test-route', function () {
            return response()->json(['message' => 'success']);
        })->middleware('tiered.throttle:test-limiter');

        Route::get('/other-route', function () {
            return response()->json(['message' => 'success']);
        })->middleware('tiered.throttle:other-limiter');

        Cache::flush();

        ThrottleBypass::resetLoggingState();
    }

    private function hit(int $n, string $ip = self::IP, string $route = '/test-route'): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson($route);
        }
    }

    /**
     * Drive an IP all the way to a lockout on the test limiter.
     */
    private function lockOut(string $ip = self::IP): void
    {
        $this->hit(11, $ip);
        $this->travel(61)->seconds();
        $this->hit(11, $ip);
        $this->travel(121)->seconds();
        $this->hit(6, $ip);
    }

    private function unban(string $ip = self::IP, array $options = []): string
    {
        Artisan::call('throttle:unban', array_merge(['ip' => $ip], $options));

        return Artisan::output();
    }

    public function test_it_says_so_when_nothing_is_throttling_the_ip(): void
    {
        $output = $this->unban();

        $this->assertStringContainsString('not currently throttled or banned', $output);
        $this->assertStringContainsString('nothing to unban', $output);
        $this->assertStringNotContainsString('has been unbanned', $output);
    }

    public function test_it_reports_the_tier_it_cleared(): void
    {
        // 11 hits breaches tier 0 once, so the IP now sits in tier 1.
        $this->hit(11);

        $output = $this->unban();

        $this->assertStringContainsString('in tier 1 of 2 with 1 offense', $output);
        $this->assertStringContainsString('has been unbanned and reset to tier 0', $output);
    }

    public function test_it_reports_the_lockout_it_cleared(): void
    {
        $this->lockOut();

        $output = $this->unban();

        $this->assertStringContainsString('locked out', $output);
        $this->assertStringContainsString('expires', $output);
        $this->assertStringContainsString('has been unbanned and reset to tier 0', $output);
    }

    public function test_it_reports_an_ip_that_is_only_being_counted(): void
    {
        $this->hit(3);

        $output = $this->unban();

        $this->assertStringContainsString('counting 3/10 in the current window, no offenses', $output);
    }

    public function test_it_clears_only_the_named_limiter(): void
    {
        $this->hit(11);

        $output = $this->unban(self::IP, ['--limiter' => 'other-limiter']);

        $this->assertStringContainsString('other-limiter: not currently throttled or banned', $output);

        // The test limiter's offense is untouched, so the IP is still blocked.
        $this->hit(1);
        $this->assertEquals(429, $this->withServerVariables(['REMOTE_ADDR' => self::IP])->getJson('/test-route')->status());
    }

    public function test_it_rejects_a_limiter_that_is_not_configured(): void
    {
        $output = $this->unban(self::IP, ['--limiter' => 'nope']);

        $this->assertStringContainsString('No limiter named [nope] is configured', $output);
        $this->assertStringContainsString('test-limiter', $output);
    }

    public function test_it_leaves_other_ips_alone(): void
    {
        $this->lockOut(self::IP);
        $this->lockOut(self::OTHER_IP);

        $this->unban(self::IP);

        $this->hit(1, self::OTHER_IP);
        $this->assertEquals(
            429,
            $this->withServerVariables(['REMOTE_ADDR' => self::OTHER_IP])->getJson('/test-route')->status()
        );
    }

    public function test_it_warns_when_throttling_is_currently_disabled(): void
    {
        config()->set('tiered-throttle.enabled', false);

        $output = $this->unban();

        $this->assertStringContainsString('Throttling is currently disabled by configuration', $output);
    }
}
