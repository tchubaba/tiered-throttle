<?php

declare(strict_types=1);

namespace Tchubaba\TieredThrottle\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Owns the package's cache key scheme and the reading/clearing of its state.
 *
 * Every key the package writes is `tt_{type}:{limiter}:{ip}`, where type is one
 * of types(). This class is the only place that spells that out: the middleware
 * builds keys through it, and throttle:unban and throttle:status read and clear
 * through it. Keeping it in one place matters because a key type that is written
 * but never cleared leaves state behind that nothing can name.
 *
 * Keys are only ever addressed by their exact name, never enumerated: no store
 * scan is attempted, so this works identically on every cache driver. The cost
 * is that nothing here can answer "which IPs does the store hold?".
 */
final class DecisionRepository
{
    private const PREFIX = 'tt_';

    /**
     * The state key types the middleware maintains for each IP.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return ['rl', 'window', 'breached', 'offense', 'lockout'];
    }

    /**
     * Build a namespaced cache key.
     */
    public function key(string $type, string $limiter, string $ip): string
    {
        return self::PREFIX."{$type}:{$limiter}:{$ip}";
    }

    /**
     * Read the current decision for one IP under one limiter.
     *
     * Reads keys by name only, so it behaves identically on every cache driver.
     * Both commands pass a limiter name validated against config, so the tiers
     * looked up here always exist.
     */
    public function inspect(string $limiter, string $ip): Decision
    {
        $config    = config("tiered-throttle.limiters.{$limiter}", []);
        $tiers     = $config['tiers'] ?? [];
        $tierCount = count($tiers);

        $offense   = (int) Cache::get($this->key('offense', $limiter, $ip), 0);
        $tierIndex = $tierCount > 0 ? min($offense, $tierCount - 1) : 0;

        $lockedOut = Cache::has($this->key('lockout', $limiter, $ip));

        return new Decision(
            limiter: $limiter,
            ip: $ip,
            lockedOut: $lockedOut,
            offense: $offense,
            tierIndex: $tierIndex,
            tierCount: $tierCount,
            hits: (int) Cache::get($this->key('rl', $limiter, $ip), 0),
            limit: $tierCount > 0 ? (int) $tiers[$tierIndex][0] : 0,
            windowReset: $this->timestamp($this->key('window', $limiter, $ip)),
            // The lockout value is its expiry timestamp. Versions before 1.1 stored a
            // bare 1 there, so an older lockout is active but its expiry is unknown.
            lockedUntil: $this->timestamp($this->key('lockout', $limiter, $ip)),
        );
    }

    /**
     * Forget every key this package holds for one IP under one limiter.
     *
     * @return bool Whether any state was actually present to clear.
     */
    public function clear(string $limiter, string $ip): bool
    {
        $existed = false;

        foreach ($this->types() as $type) {
            $key = $this->key($type, $limiter, $ip);

            if (Cache::has($key)) {
                $existed = true;
            }

            Cache::forget($key);
        }

        return $existed;
    }

    /**
     * Read a cache value as a unix timestamp, or null if it is not one.
     */
    private function timestamp(string $key): ?int
    {
        $value = Cache::get($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
