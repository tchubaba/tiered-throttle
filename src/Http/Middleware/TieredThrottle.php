<?php

declare(strict_types=1);

namespace Tchubaba\TieredThrottle\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

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
    /**
     * Handle an incoming request, applying the named tiered rate limiter.
     *
     * @param  string  $limiterName  Named limiter key in config/tiered-throttle.php
     */
    public function handle(Request $request, Closure $next, string $limiterName): Response
    {
        $config = config("tiered-throttle.limiters.{$limiterName}");

        if ( ! $config) {
            return $next($request);
        }

        $ip = $request->ip();

        // 1. Check active lockout
        if (Cache::has($this->key('lockout', $limiterName, $ip))) {
            return $this->lockoutResponse();
        }

        // 2. Resolve current tier from offense count
        $offense          = (int) Cache::get($this->key('offense', $limiterName, $ip), 0);
        $tierIndex        = min($offense, count($config['tiers']) - 1);
        [$limit, $window] = $config['tiers'][$tierIndex];

        $rlKey     = $this->key('rl', $limiterName, $ip);
        $windowKey = $this->key('window', $limiterName, $ip);

        // 3. Seed window on first hit (atomic: only sets if key absent)
        if (Cache::add($rlKey, 0, $window)) {
            Cache::put($windowKey, now()->addSeconds($window)->timestamp, $window + 5);
        }

        // 4. Increment hit counter
        $hits = Cache::increment($rlKey);

        // 5. Check limit
        if ($hits > $limit) {
            $breachKey = $this->key('breached', $limiterName, $ip);

            // Escalate offense only once per window
            if (Cache::add($breachKey, 1, $window)) {
                $newOffense = $offense + 1;
                Cache::put($this->key('offense', $limiterName, $ip), $newOffense, $config['offense_ttl']);

                if ($newOffense >= count($config['tiers'])) {
                    Cache::put($this->key('lockout', $limiterName, $ip), 1, $config['lockout_seconds']);

                    return $this->lockoutResponse();
                }
            }

            $resetAt = (int) Cache::get($windowKey, now()->addSeconds($window)->timestamp);

            return $this->throttleResponse($resetAt);
        }

        return $next($request);
    }

    /**
     * Build a namespaced cache key.
     */
    private function key(string $type, string $limiterName, string $ip): string
    {
        return "tt_{$type}:{$limiterName}:{$ip}";
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
