<?php

declare(strict_types=1);

namespace Tchubaba\TieredThrottle\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether a request skips throttling, and why.
 *
 * SECURITY MODEL — read this before changing anything here.
 *
 * Every lever below is gated on the runtime environment being listed in
 * config('tiered-throttle.bypass_environments'), which defaults to ['local',
 * 'testing']. The gate is deliberately an allowlist rather than a "not
 * production" check: Application::isProduction() is an exact match on
 * 'production', so an app running under 'prod', 'staging' or a typo would be
 * treated as non-production by a denylist and silently lose its rate limiting.
 * With an allowlist, an unrecognised environment name disables the bypass, not
 * the limiter.
 *
 * The gate is evaluated per request rather than baked into the config default so
 * that a bootstrap/cache/config.php built on a development machine cannot carry
 * enabled=false onto a host whose environment is not allowlisted. It is defence
 * in depth, not permission to commit cached config.
 *
 * The IP allowlist is gated the same way on purpose: this is a development
 * affordance, not a production allowlist. Committing bypass_ips must not be able
 * to exempt an address from rate limiting on a live host.
 */
final class ThrottleBypass
{
    /**
     * Dedupe flags are static so a bypassed app logs once per worker process
     * instead of once per request.
     */
    private static bool $bypassWarned = false;

    private static bool $refusalLogged = false;

    public function __construct(
        protected Application $app,
    ) {
    }

    /**
     * Return a human-readable reason the request is exempt, or null if it is not.
     *
     * @param  string|null  $ip  The IP the middleware would key on.
     */
    public function reasonFor(?string $ip): ?string
    {
        $config       = config('tiered-throttle', []);
        $environments = $config['bypass_environments'] ?? ['local', 'testing'];

        // Coerced rather than compared with === false so that a .env value of
        // "false", "0", "off" or "no" all mean the same thing.
        $enabled = filter_var($config['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if (! $this->app->environment($environments)) {
            if (! $enabled) {
                $this->refuseDisabledFlag($environments);

                return null;
            }

            return null;
        }

        if (! $enabled) {
            $this->warn('Tiered throttling is disabled: config(\'tiered-throttle.enabled\') is false in a bypass-allowed environment.');

            return 'throttling disabled by configuration';
        }

        if ($ip !== null && in_array($ip, $config['bypass_ips'] ?? [], true)) {
            $this->warn("Tiered throttling skipped for allowlisted IP {$ip}.");

            return "allowlisted IP {$ip}";
        }

        if (! empty($config['bypass_private_ranges']) && $this->isPrivateRange($ip)) {
            $this->warn("Tiered throttling skipped for private-range IP {$ip}.");

            return "private-range IP {$ip}";
        }

        return null;
    }

    /**
     * Whether the kill switch was turned off somewhere the guard will not honour.
     *
     * This is the misconfiguration worth an error log: someone set enabled=false
     * and the limiter is still running, so anything they "tested" was enforced.
     */
    private function refuseDisabledFlag(array $environments): void
    {
        if (self::$refusalLogged) {
            return;
        }

        self::$refusalLogged = true;

        Log::error(
            'tiered-throttle: config(\'tiered-throttle.enabled\') is false, but the current environment is not in '.
            'bypass_environments ('.implode(', ', $environments).'), so throttling remains active. '.
            'Set bypass_environments deliberately if you intend to disable throttling here.'
        );
    }

    private function warn(string $message): void
    {
        if (self::$bypassWarned) {
            return;
        }

        self::$bypassWarned = true;

        Log::warning('tiered-throttle: '.$message);
    }

    /**
     * Whether the IP is loopback, private or reserved (RFC1918, link-local,
     * carrier-grade NAT and other reserved blocks, including Docker's bridge).
     *
     * A value that is not an IP at all is not private: it gets throttled.
     */
    private function isPrivateRange(?string $ip): bool
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * Clear the once-per-process log dedupe flags.
     *
     * @internal Test only: static state outlives a single test.
     */
    public static function resetLoggingState(): void
    {
        self::$bypassWarned = false;
        self::$refusalLogged = false;
    }
}
