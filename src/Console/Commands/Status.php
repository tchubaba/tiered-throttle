<?php

declare(strict_types=1);

namespace Tchubaba\TieredThrottle\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Tchubaba\TieredThrottle\Support\Decision;
use Tchubaba\TieredThrottle\Support\DecisionRepository;
use Tchubaba\TieredThrottle\Support\ThrottleBypass;

/**
 * Reports the throttling decision held for one IP, under each limiter.
 *
 * It answers "what state is this IP in?" by reading the five known key names
 * directly, which works identically on every cache driver and needs no scanning,
 * so nothing here depends on what the store is capable of enumerating.
 *
 * What it deliberately cannot do is list which IPs the store holds. Recovering
 * those means scanning the cache, and a scan's behaviour varies by driver *and*
 * by driver version, so a listing would be quietly unreliable on exactly the
 * stores you are most likely to be using. If you need to know which address a
 * request was keyed on, log it from the application instead.
 */
class Status extends Command
{
    protected $signature = 'throttle:status
                            {ip : The IP address to inspect}
                            {--limiter= : Restrict the report to one limiter}
                            {--banned : Only report an active lockout}
                            {--all : Also report an IP that is only being counted, with no offense yet}
                            {--json : Emit JSON instead of a table}';

    protected $description = 'Report what this package currently holds against a single IP, across every limiter';

    public function handle(DecisionRepository $keys, ThrottleBypass $bypass): int
    {
        $configured = array_keys(config('tiered-throttle.limiters', []));
        $limiter    = $this->option('limiter');

        if ($limiter && ! in_array($limiter, $configured, true)) {
            $this->error(sprintf(
                'No limiter named [%s] is configured. Configured limiters: %s.',
                $limiter,
                $configured === [] ? 'none' : implode(', ', $configured)
            ));

            return self::FAILURE;
        }

        if ($configured === []) {
            $this->error('No limiters are configured in config/tiered-throttle.php, so nothing can be holding a decision.');

            return self::FAILURE;
        }

        $ip = (string) $this->argument('ip');

        $decisions = $this->filter(
            $this->decisionsForIps($keys, $limiter ? [$limiter] : $configured, $ip),
            $limiter
        );

        if ($bypass->reasonFor(null) !== null) {
            $this->warn('Throttling is currently disabled by configuration — the state below is whatever was recorded before it was switched off.');
        }

        if ($this->option('json')) {
            return $this->renderJson($ip, $decisions);
        }

        $this->renderTable($ip, $decisions);

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $limiters
     * @return list<Decision>
     */
    private function decisionsForIps(DecisionRepository $keys, array $limiters, string $ip): array
    {
        return array_map(
            static fn (string $limiter): Decision => $keys->inspect($limiter, $ip),
            $limiters
        );
    }

    /**
     * Apply --banned / --all / --limiter.
     *
     * A bare hit counter with no offense is "counting", not a decision, so it is
     * only shown when --all asks for it.
     *
     * @param  list<Decision>  $decisions
     * @return list<Decision>
     */
    private function filter(array $decisions, ?string $limiter): array
    {
        return array_values(array_filter($decisions, function (Decision $decision) use ($limiter): bool {
            if ($limiter !== null && $decision->limiter !== $limiter) {
                return false;
            }

            if ($this->option('banned')) {
                return $decision->isLockedOut();
            }

            if ($this->option('all')) {
                return $decision->isActive() || $decision->isCounting();
            }

            return $decision->isActive();
        }));
    }

    /**
     * @param  list<Decision>  $decisions
     */
    private function renderTable(string $ip, array $decisions): void
    {
        if ($decisions === []) {
            $this->info($this->noneMessage($ip));

            return;
        }

        $this->table(
            ['Limiter', 'IP', 'State', 'Offenses', 'Hits/Limit', 'Window resets', 'Locked until'],
            array_map([$this, 'row'], $decisions)
        );
    }

    /**
     * Say which filter came up empty, rather than implying the IP is clean when
     * --banned only asked about lockouts.
     */
    private function noneMessage(string $ip): string
    {
        if ($this->option('banned')) {
            return "No limiter has an active lockout for {$ip}.";
        }

        if ($this->option('all')) {
            return "No limiter holds any state for {$ip}, not even a hit counter.";
        }

        return "No limiter has any decision recorded for {$ip}.";
    }

    /**
     * @return list<string>
     */
    private function row(Decision $decision): array
    {
        return [
            $decision->limiter,
            $decision->ip,
            $decision->state(),
            $decision->offense,
            $decision->hits.'/'.$decision->limit,
            $this->when($decision->windowReset),
            $this->when($decision->lockedUntil),
        ];
    }

    private function when(?int $timestamp): string
    {
        if ($timestamp === null) {
            return '-';
        }

        return Carbon::createFromTimestamp($timestamp)->format('H:i:s').' ('.Carbon::createFromTimestamp($timestamp)->diffForHumans().')';
    }

    /**
     * @param  list<Decision>  $decisions
     */
    private function renderJson(string $ip, array $decisions): int
    {
        $this->line(json_encode([
            'ip'        => $ip,
            'decisions' => array_map(static fn (Decision $d): array => [
                'limiter'     => $d->limiter,
                'ip'          => $d->ip,
                'state'       => $d->state(),
                'offenses'    => $d->offense,
                'tier'        => $d->tierIndex,
                'hits'        => $d->hits,
                'limit'       => $d->limit,
                'windowReset' => $d->windowReset,
                'lockedUntil' => $d->lockedUntil,
            ], $decisions),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
