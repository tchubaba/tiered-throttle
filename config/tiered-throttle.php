<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tiered Rate Limiter Definitions
    |--------------------------------------------------------------------------
    |
    | Each entry under 'limiters' defines a named tiered rate limiter that
    | can be applied to a route via the 'tiered.throttle:<name>' middleware.
    |
    | HOW IT WORKS
    | ------------
    | On each request the middleware resolves the IP's current tier from its
    | offense count, then increments a hit counter for that tier's time window.
    | If the counter exceeds the tier's limit, the request is blocked (HTTP 429)
    | and the offense count is incremented by one — but only once per window,
    | no matter how many requests are made while blocked.
    |
    | On the next window the IP starts fresh within its new (stricter) tier.
    | Once all tiers are exhausted the IP is locked out entirely.
    |
    | TIERS
    | -----
    | 'tiers' is an ordered array of [maxAttempts, windowSeconds] pairs.
    | Tiers are traversed from index 0 (most lenient) upward. Each time an IP
    | exceeds a tier's limit it accumulates one offense and moves to the next
    | tier on the following window. Any number of tiers can be defined.
    |
    | Example progression for a 3-tier limiter:
    |   Offense 0 → Tier 0 (most lenient)
    |   Offense 1 → Tier 1
    |   Offense 2 → Tier 2 (strictest)
    |   Offense 3 → Lockout (offense count ≥ number of tiers)
    |
    | LOCKOUT
    | -------
    | 'lockout_seconds' controls how long a locked-out IP is blocked entirely.
    | During lockout all requests return HTTP 429 with the X-RateLimit-Lockout: 1
    | header. When the lockout expires the IP re-enters the last (strictest) tier
    | — it does NOT reset to tier 0 unless the offense TTL has also expired.
    |
    | OFFENSE TTL & FORGIVENESS
    | -------------------------
    | 'offense_ttl' is the number of seconds the offense counter survives without
    | being refreshed. Every new offense resets this TTL. An IP that stops
    | misbehaving long enough for the TTL to expire will return to tier 0 on its
    | next request. Lockout expiry alone does not reset the offense counter.
    |
    | The only ways to reach a full tier-0 reset are:
    |   1. The offense TTL expires naturally (no new offenses during that period).
    |   2. Manual intervention via: php artisan throttle:unban <ip>
    |
    | DEVELOPMENT ESCAPE HATCHES
    | --------------------------
    | Two levers stop you tripping the limiter while working locally, without
    | being able to switch it off on a live host by accident. Both are gated on
    | the runtime environment being listed in 'bypass_environments' (default:
    | local, testing).
    |
    | That gate is an allowlist on purpose. Application::isProduction() matches
    | the string 'production' exactly, so a "not production" denylist would treat
    | 'prod', 'staging' or a typo as non-production and silently drop your rate
    | limiting. With an allowlist, an unrecognised environment name disables the
    | bypass, not the limiter.
    |
    | The gate is evaluated per request rather than baked into the values below,
    | so a bootstrap/cache/config.php built on a development machine cannot carry
    | enabled=false onto a host whose environment is not allowlisted. That is a
    | backstop, not permission to commit cached config.
    |
    | 'enabled'                false switches the middleware off entirely — for
    |                          load tests or seeding. Prefer 'bypass_ips' for
    |                          ordinary development: the middleware keeps running
    |                          for every other IP, so a bug in it cannot hide
    |                          until production.
    | 'bypass_ips'             IPs that skip throttling. Gated by
    |                          'bypass_environments' too, so committing this list
    |                          cannot exempt an address on a live host. This is a
    |                          development affordance, not a production allowlist.
    | 'bypass_private_ranges'  Exempt loopback, RFC1918, link-local and other
    |                          reserved ranges — including the Docker bridge
    |                          network, which is the address a container sees for
    |                          requests from your own machine. Off by default.
    |
    | 'testing' is in the default allowlist, so setting TIERED_THROTTLE_ENABLED
    | to false globally also disables throttling inside your own feature tests.
    | Assert against enabled=true.
    |
    | To see what a given IP is currently subject to:
    |   php artisan throttle:status <ip>
    |
    | It reads the state keys by name, so it behaves the same on every cache
    | driver. What it does NOT do is list which IPs the store holds: recovering
    | those means scanning the cache, and scan behaviour varies by driver *and*
    | by driver version, so a listing would be unreliable on exactly the stores
    | you are most likely to run. If you need to know which address a request was
    | keyed on — the usual reason being that a container sees its network gateway
    | rather than your machine — log the IP from the application instead.
    |
    | LIMITER NAMES
    | -------------
    | Cache keys are tt_{type}:{limiter}:{ip}, built by plain concatenation, so a
    | limiter name must not contain a colon: 'a:b' for one IP and 'a' for an IP
    | starting with 'b:' would produce the same key.
    |
    */

    'enabled'                 => env('TIERED_THROTTLE_ENABLED', true),

    'bypass_ips'              => [],

    'bypass_private_ranges'   => env('TIERED_THROTTLE_BYPASS_PRIVATE_RANGES', false),

    'bypass_environments'     => ['local', 'testing'],

    'limiters' => [

        'default' => [
            'tiers' => [
                [60, 60],   // Tier 0: 60 attempts per 1 minute
                [30, 60],   // Tier 1: 30 attempts per 1 minute
                [10, 60],   // Tier 2: 10 attempts per 1 minute
            ],
            'lockout_seconds' => 3600, // 1 hour - third offense triggers full lockout
            'offense_ttl'     => 3600, // 1 hour - offense counter resets after 1h clean
        ],

    ],

];
