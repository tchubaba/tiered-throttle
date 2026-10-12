# Tiered Throttle

A progressive, tier-based rate limiter for Laravel. Instead of a binary "allowed or blocked" approach, this package tracks IP reputation and escalates through increasingly strict tiers of rate limiting before reaching a full lockout.

## Features

- **Tier-based escalation:** Move users through multiple levels of throttling (e.g., Tier 0 → Tier 1 → Tier 2 → Lockout).
- **Reputation-based:** Penalties persist via an "offense count" in the cache.
- **Anti-Flood Escalation:** Only one offense escalation is allowed per time window, preventing a single burst from causing an immediate ban.
- **Forgiveness:** Offenses automatically expire after a configurable period of clean behavior.
- **Lockout:** Total ban for a set period after all tiers are exhausted.
- **Development escape hatches:** a kill switch, an IP allowlist and a private-range exemption, so you can work locally without tripping the limiter.

## Installation

Requires PHP 8.2 or newer and Laravel 12 or 13.

You can install the package via composer:

```bash
composer require tchubaba/tiered-throttle
```

Then, publish the configuration file:

```bash
php artisan vendor:publish --tag="tiered-throttle-config"
```

## Configuration

The configuration file `config/tiered-throttle.php` allows you to define named limiters with their own tiers:

```php
'limiters' => [
    'show-snipto' => [
        'tiers' => [
            [20, 60],   // Tier 0: 20 attempts per 1 minute (baseline)
            [10, 120],  // Tier 1: 10 attempts per 2 minutes (first offense)
            [5,  300],  // Tier 2: 5 attempts per 5 minutes (second offense)
        ],
        'lockout_seconds' => 43200, // 12 hours lockout after third offense
        'offense_ttl'     => 7200,  // 2 hours of clean behavior to reset to Tier 0
    ],
],
```

### Development escape hatches

Three options stop you tripping the limiter while working locally. They take effect only when the app's environment is listed in `bypass_environments` (default `['local', 'testing']`); on any other environment the limiter runs regardless of how these are set.

```php
'enabled'                 => env('TIERED_THROTTLE_ENABLED', true),
'bypass_ips'              => [],
'bypass_private_ranges'   => env('TIERED_THROTTLE_BYPASS_PRIVATE_RANGES', false),
'bypass_environments'     => ['local', 'testing'],
```

- `enabled => false` switches the middleware off entirely — useful for load tests or seeding. For ordinary development `bypass_ips` is the better choice, because the middleware keeps running for every other IP.
- `bypass_ips` exempts the addresses you list.
- `bypass_private_ranges` exempts loopback, RFC1918, link-local and other reserved ranges, including the Docker bridge network — the address a container sees for requests from your own machine. Off by default.

`testing` is in the default allowlist, so setting `TIERED_THROTTLE_ENABLED=false` globally also disables throttling inside your feature tests.

## Usage

Apply the middleware to your routes using the `tiered.throttle` alias followed by the limiter name:

```php
Route::get('/api/data', function () {
    // ...
})->middleware('tiered.throttle:show-snipto');
```

The limiter name after the colon must be defined under `limiters` in `config/tiered-throttle.php`. If it is not, the package throws an `InvalidArgumentException` on any environment except `production`, where it logs an error and lets the request through unthrottled rather than taking the endpoint down. A typo in a deployed route therefore disables throttling for that route and records an error in your log.

## Console Commands

### Unban an IP

If an IP has been locked out or reached a high tier, you can reset it manually:

```bash
php artisan throttle:unban 1.2.3.4
```

You can also specify a specific limiter:

```bash
php artisan throttle:unban 1.2.3.4 --limiter=show-snipto
```

The command reports what it found for that IP: a lockout and when it would have expired, which tier it was sitting in, a bare hit counter, or nothing to clear. A `--limiter` name that is not in config is an error.

### Inspect an IP

To see what one IP is currently subject to, across every configured limiter:

```bash
php artisan throttle:status 1.2.3.4
```

The report shows one row per limiter: its state for that IP, the offense count, hits against the current limit, when the current window resets, and when a lockout ends.

```bash
php artisan throttle:status 1.2.3.4 --limiter=show-snipto   # one limiter only
php artisan throttle:status 1.2.3.4 --banned                # only an active lockout
php artisan throttle:status 1.2.3.4 --all                   # include a bare hit counter
php artisan throttle:status 1.2.3.4 --json                  # machine-readable
```

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
