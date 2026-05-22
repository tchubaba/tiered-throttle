<?php

declare(strict_types=1);

namespace Tchubaba\TieredThrottle\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tchubaba\TieredThrottle\Tests\TestCase;

class TieredThrottleTest extends TestCase
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
    }

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

    public function test_it_allows_requests_within_first_tier_limit(): void
    {
        $responses = $this->hit(10);

        foreach ($responses as $i => $response) {
            $this->assertNotEquals(429, $response->status(), 'Request #'.($i + 1).' unexpectedly returned 429');
        }
    }

    public function test_it_blocks_on_the_eleventh_request_in_tier_zero(): void
    {
        $responses = $this->hit(11);

        $this->assertEquals(429, $this->lastStatus($responses));
    }

    public function test_it_returns_x_ratelimit_reset_header_on_throttle(): void
    {
        $responses = $this->hit(11);
        $last      = end($responses);

        $last->assertStatus(429);
        $this->assertNotEmpty($last->headers->get('X-RateLimit-Reset'));
        $this->assertGreaterThan(time(), (int) $last->headers->get('X-RateLimit-Reset'));
    }

    public function test_it_only_escalates_offense_count_once_per_window(): void
    {
        // Exhaust tier 0 (11 → offense escalates to 1)
        $this->hit(20);

        // Travel past tier 0 window (60 seconds)
        $this->travel(61)->seconds();

        // Now in tier 1: 10 attempts per 120 seconds
        $responses = $this->hit(10);
        foreach ($responses as $response) {
            $this->assertNotEquals(429, $response->status());
        }

        $blocked = $this->hit(1);
        $this->assertEquals(429, $this->lastStatus($blocked));
    }

    public function test_it_locks_out_after_all_tiers_exhausted(): void
    {
        // Exhaust tier 0
        $this->hit(11);
        $this->travel(61)->seconds();

        // Exhaust tier 1
        $this->hit(11);
        $this->travel(121)->seconds();

        // Exhaust tier 2 (offense → 3, which >= 3 tiers → lockout)
        $responses = $this->hit(6);
        $last      = end($responses);

        $last->assertStatus(429);
        $this->assertEquals('1', $last->headers->get('X-RateLimit-Lockout'));
        $this->assertNull($last->headers->get('X-RateLimit-Reset'));
    }

    public function test_it_unban_command_clears_lockout(): void
    {
        // Trigger lockout
        $this->hit(11);
        $this->travel(61)->seconds();
        $this->hit(11);
        $this->travel(121)->seconds();
        $this->hit(6);

        $locked = $this->hit(1);
        $this->assertEquals(429, $this->lastStatus($locked));

        // Run unban command
        Artisan::call('throttle:unban', ['ip' => self::IP]);

        // Should be allowed again
        $response = $this->hit(1);
        $this->assertNotEquals(429, $this->lastStatus($response));
    }
}
