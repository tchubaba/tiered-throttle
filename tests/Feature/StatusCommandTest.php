<?php

declare(strict_types=1);

namespace Tchubaba\TieredThrottle\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Exception\RuntimeException;
use Tchubaba\TieredThrottle\Support\ThrottleBypass;
use Tchubaba\TieredThrottle\Tests\TestCase;

/**
 * throttle:status inspects one IP by reading the five known key names.
 *
 * Nothing here depends on the cache driver's ability to enumerate keys, so the
 * array store is enough to cover the whole command. There is deliberately no
 * listing mode: see the command's docblock for why.
 */
class StatusCommandTest extends TestCase
{
    private const IP = '1.2.3.4';

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

    private function lockOut(string $ip = self::IP, string $route = '/test-route'): void
    {
        $this->hit(11, $ip, $route);
        $this->travel(61)->seconds();
        $this->hit(11, $ip, $route);
        $this->travel(121)->seconds();
        $this->hit(6, $ip, $route);
    }

    /**
     * @return array{0: int, 1: string}  exit code and output
     */
    private function runStatus(array $args = []): array
    {
        $code = Artisan::call('throttle:status', $args);

        return [$code, Artisan::output()];
    }

    public function test_it_reports_a_locked_out_ip(): void
    {
        $this->lockOut();

        [$code, $output] = $this->runStatus(['ip' => self::IP]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString(self::IP, $output);
        $this->assertStringContainsString('locked out', $output);
        $this->assertStringContainsString('test-limiter', $output);
    }

    public function test_it_reports_nothing_for_a_clean_ip(): void
    {
        [$code, $output] = $this->runStatus(['ip' => self::IP]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No limiter has any decision recorded for '.self::IP, $output);
    }

    public function test_it_reports_every_limiter_the_ip_is_known_to(): void
    {
        $this->hit(11);
        $this->hit(6, self::IP, '/other-route');

        [$code, $output] = $this->runStatus(['ip' => self::IP, '--all' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('test-limiter', $output);
        $this->assertStringContainsString('other-limiter', $output);
    }

    public function test_the_limiter_option_narrows_the_report_to_one_limiter(): void
    {
        $this->hit(11);
        $this->hit(6, self::IP, '/other-route');

        [$code, $output] = $this->runStatus(['ip' => self::IP, '--limiter' => 'test-limiter']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('test-limiter', $output);
        $this->assertStringNotContainsString('other-limiter', $output);
    }

    public function test_the_banned_flag_hides_an_ip_that_is_only_in_a_tier(): void
    {
        $this->hit(11);

        [$code, $output] = $this->runStatus(['ip' => self::IP, '--banned' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No limiter has an active lockout for '.self::IP, $output);
    }

    public function test_the_banned_flag_keeps_a_locked_out_ip(): void
    {
        $this->lockOut();

        [, $output] = $this->runStatus(['ip' => self::IP, '--banned' => true]);

        $this->assertStringContainsString('locked out', $output);
    }

    public function test_it_hides_a_bare_hit_counter_unless_asked_for(): void
    {
        $this->hit(3);

        [, $filtered] = $this->runStatus(['ip' => self::IP]);
        $this->assertStringContainsString('No limiter has any decision recorded for '.self::IP, $filtered);

        [, $withAll] = $this->runStatus(['ip' => self::IP, '--all' => true]);
        $this->assertStringContainsString('counting', $withAll);
        $this->assertStringContainsString('3/10', $withAll);
    }

    public function test_it_emits_json(): void
    {
        $this->lockOut();

        [$code, $output] = $this->runStatus(['ip' => self::IP, '--json' => true]);

        $this->assertSame(0, $code);

        $payload = json_decode($output, true);

        $this->assertIsArray($payload);
        $this->assertSame(self::IP, $payload['ip']);
        $this->assertCount(1, $payload['decisions']);

        $decision = $payload['decisions'][0];

        $this->assertSame('test-limiter', $decision['limiter']);
        $this->assertSame(self::IP, $decision['ip']);
        $this->assertSame('locked out', $decision['state']);
        $this->assertSame(3, $decision['offenses']);
        $this->assertIsInt($decision['lockedUntil']);
        $this->assertGreaterThan(time(), $decision['lockedUntil']);
    }

    public function test_it_rejects_a_limiter_that_is_not_configured(): void
    {
        [$code, $output] = $this->runStatus(['ip' => self::IP, '--limiter' => 'nope']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('No limiter named [nope] is configured', $output);
        $this->assertStringContainsString('test-limiter', $output);
    }

    public function test_it_requires_an_ip_because_listing_is_not_offered(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments');

        Artisan::call('throttle:status');
    }

    public function test_it_warns_when_throttling_is_currently_disabled(): void
    {
        config()->set('tiered-throttle.enabled', false);

        [$code, $output] = $this->runStatus(['ip' => self::IP]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Throttling is currently disabled by configuration', $output);
    }
}
