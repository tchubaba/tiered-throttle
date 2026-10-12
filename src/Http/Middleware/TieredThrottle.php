<?php

declare(strict_types=1);

namespace Tchubaba\TieredThrottle\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Tchubaba\TieredThrottle\Support\DecisionRepository;
use Tchubaba\TieredThrottle\Support\ThrottleBypass;

/**
 * Tiered rate-limiting middleware.
 *
 * Applies a named, tier-based rate limiter to a route. Each IP is assigned a tier
 * determined by its accumulated offense count. Tiers are defined in
 * config/tiered-throttle.php under 'limiters.<name>.tiers' as ordered
 * [maxAttempts, windowSeconds] pairs. Tier 0 is the most lenient; higher tiers
 * are progressively stricter.
 */
class TieredThrottle
{
    public function __construct(
        private DecisionRepository $keys,
        private ThrottleBypass $bypass,
        private Application $app,
    ) {
    }

    /**
     * Handle an incoming request, applying the named tiered rate limiter.
     *
     * @param  string  $limiterName  Named limiter key in config/tiered-throttle.php
     */
    public function handle(Request $request, Closure $next, string $limiterName): Response
    {
        $config = config("tiered-throttle.limiters.{$limiterName}");

        if (! $config) {
            return $this->handleUnknownLimiter($limiterName, $request, $next);
        }

        $ip = $request->ip();

        // 0. Development escape hatches: the kill switch and the IP allowlist.
        // Checked after the limiter is resolved so a misconfigured limiter name
        // stays loud even while throttling is otherwise switched off.
        if ($this->bypass->reasonFor($ip) !== null) {
            return $next($request);
        }

        // 1. Check active lockout
        if (Cache::has($this->keys->key('lockout', $limiterName, $ip))) {
            return $this->lockoutResponse();
        }

        // 2. Resolve current tier from offense count
        $offense          = (int) Cache::get($this->keys->key('offense', $limiterName, $ip), 0);
        $tierIndex        = min($offense, count($config['tiers']) - 1);
        [$limit, $window] = $config['tiers'][$tierIndex];

        $rlKey     = $this->keys->key('rl', $limiterName, $ip);
        $windowKey = $this->keys->key('window', $limiterName, $ip);

        // 3. Seed window on first hit (atomic: only sets if key absent)
        if (Cache::add($rlKey, 0, $window)) {
            Cache::put($windowKey, now()->addSeconds($window)->timestamp, $window + 5);
        }

        // 4. Increment hit counter
        $hits = Cache::increment($rlKey);

        // 5. Check limit
        if ($hits > $limit) {
            $breachKey = $this->keys->key('breached', $limiterName, $ip);

            // Escalate offense only once per window
            if (Cache::add($breachKey, 1, $window)) {
                $newOffense = $offense + 1;
                Cache::put($this->keys->key('offense', $limiterName, $ip), $newOffense, $config['offense_ttl']);

                if ($newOffense >= count($config['tiers'])) {
                    // The value is the expiry timestamp, not a flag, so that
                    // throttle:status and throttle:unban can report when the
                    // lockout ends. Cache::has() still decides enforcement.
                    Cache::put(
                        $this->keys->key('lockout', $limiterName, $ip),
                        now()->addSeconds($config['lockout_seconds'])->timestamp,
                        $config['lockout_seconds']
                    );

                    return $this->lockoutResponse();
                }
            }

            $resetAt = (int) Cache::get($windowKey, now()->addSeconds($window)->timestamp);

            return $this->throttleResponse($resetAt);
        }

        return $next($request);
    }

    /**
     * Deal with a route that names a limiter config does not define.
     *
     * This used to fall through silently, which meant a typo in
     * ->middleware('tiered.throttle:...') quietly removed rate limiting from that
     * route. It is now an error everywhere except production, where a typo on a
     * deployed route must not take the endpoint down.
     */
    private function handleUnknownLimiter(string $limiterName, Request $request, Closure $next): Response
    {
        $configured = array_keys(config('tiered-throttle.limiters', []));

        $message = sprintf(
            'tiered-throttle: no limiter named [%s] is configured. Configured limiters: %s.',
            $limiterName,
            $configured === [] ? 'none' : implode(', ', $configured)
        );

        // environment('production') is equivalent to isProduction(); the
        // foundation contract declares the former but not the latter.
        if ($this->app->environment('production')) {
            Log::error($message.' The request was allowed through unthrottled.');

            return $next($request);
        }

        throw new InvalidArgumentException($message);
    }

    /**
     * Return a 429 lockout response.
     */
    private function lockoutResponse(): JsonResponse
    {
        return response()->json(['message' => 'Too Many Attempts.'], 429, [
            'X-RateLimit-Lockout' => '1',
        ]);
    }

    /**
     * Return a 429 throttle response with window expiry headers.
     */
    private function throttleResponse(int $resetTimestamp): JsonResponse
    {
        return response()->json(['message' => 'Too Many Attempts.'], 429, [
            'X-RateLimit-Reset' => $resetTimestamp,
            'Retry-After'       => max(0, $resetTimestamp - time()),
        ]);
    }
}
