<?php

declare(strict_types=1);

namespace Tchubaba\TieredThrottle\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Tchubaba\TieredThrottle\Support\Decision;
use Tchubaba\TieredThrottle\Support\DecisionRepository;
use Tchubaba\TieredThrottle\Support\ThrottleBypass;

/**
 * Clears every throttling decision held for one IP.
 *
 * The limiters it clears are the ones named in config/tiered-throttle.php. Keys
 * left behind by a limiter that has since been removed from config are not
 * reachable by name, and do not need to be: the middleware resolves config
 * before it reads any key, so those keys enforce nothing, and every key type is
 * written with a TTL, so they expire on their own.
 */
class UnbanIp extends Command
{
    protected $signature = 'throttle:unban
                            {ip : The IP address to unban}
                            {--limiter= : Specific limiter name to clear (default: all)}';

    protected $description = 'Unban an IP address and reset it to tier 0 for all or a specific limiter';

    public function handle(DecisionRepository $keys, ThrottleBypass $bypass): int
    {
        $ip      = (string) $this->argument('ip');
        $limiter = $this->option('limiter');

        $configured = array_keys(config('tiered-throttle.limiters', []));

        if ($limiter) {
            if (! in_array($limiter, $configured, true)) {
                $this->error(sprintf(
                    'No limiter named [%s] is configured. Configured limiters: %s.',
                    $limiter,
                    $configured === [] ? 'none' : implode(', ', $configured)
                ));

                return self::FAILURE;
            }

            $limiters = [$limiter];
        } else {
            $limiters = $configured;
        }

        if ($limiters === []) {
            $this->error('No limiters are configured in config/tiered-throttle.php, so there is nothing to clear.');

            return self::FAILURE;
        }

        if ($bypass->reasonFor(null) !== null) {
            $this->warn('Throttling is currently disabled by configuration, so nothing here was being enforced.');
        }

        $cleared = 0;

        foreach ($limiters as $name) {
            $decision = $keys->inspect($name, $ip);

            if (! $decision->isActive() && ! $decision->isCounting()) {
                $this->line(sprintf('  %s: not currently throttled or banned — nothing to clear.', $name));

                continue;
            }

            $this->line(sprintf('  %s: %s — cleared.', $name, $this->describe($decision)));

            $keys->clear($name, $ip);
            $cleared++;
        }

        if ($cleared === 0) {
            $this->info("IP {$ip} is not currently throttled or banned by any limiter — nothing to unban.");

            return self::SUCCESS;
        }

        $this->info("IP {$ip} has been unbanned and reset to tier 0 ({$cleared} limiter".($cleared === 1 ? '' : 's').' cleared).');

        return self::SUCCESS;
    }

    /**
     * What was in place before it got cleared.
     */
    private function describe(Decision $decision): string
    {
        if ($decision->isLockedOut()) {
            return $decision->lockedUntil === null
                ? 'locked out'
                : sprintf('locked out, expires %s', $this->formatTime($decision->lockedUntil));
        }

        if ($decision->offense > 0) {
            return sprintf(
                'in tier %d of %d with %d offense%s',
                $decision->tierIndex,
                $decision->tierCount - 1,
                $decision->offense,
                $decision->offense === 1 ? '' : 's'
            );
        }

        return sprintf('counting %d/%d in the current window, no offenses', $decision->hits, $decision->limit);
    }

    private function formatTime(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp)->format('Y-m-d H:i:s');
    }
}
