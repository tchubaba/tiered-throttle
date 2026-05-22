# Tiered Throttle

A progressive, tier-based rate limiter for Laravel. Instead of a binary "allowed or blocked" approach, this package tracks IP reputation and escalates through increasingly strict tiers of rate limiting before reaching a full lockout.

## Features

- **Tier-based escalation:** Move users through multiple levels of throttling (e.g., Tier 0 → Tier 1 → Tier 2 → Lockout).
- **Reputation-based:** Penalties persist via an "offense count" in the cache.
- **Anti-Flood Escalation:** Only one offense escalation is allowed per time window, preventing a single burst from causing an immediate ban.
- **Forgiveness:** Offenses automatically expire after a configurable period of clean behavior.
- **Lockout:** Total ban for a set period after all tiers are exhausted.

## Installation

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

## Usage

Apply the middleware to your routes using the `tiered.throttle` alias followed by the limiter name:

```php
Route::get('/api/data', function () {
    // ...
})->middleware('tiered.throttle:show-snipto');
```

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

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
