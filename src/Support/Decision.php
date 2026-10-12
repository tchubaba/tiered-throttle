<?php

declare(strict_types=1);

namespace Tchubaba\TieredThrottle\Support;

/**
 * The throttling state the package currently holds for one IP under one limiter.
 *
 * Produced by DecisionRepository::inspect(). A "decision" means the IP has been
 * acted on — it is locked out, or it has at least one offense and therefore sits
 * in a tier. An IP with only a hit counter is merely being counted and has not
 * triggered anything.
 */
final readonly class Decision
{
    public function __construct(
        public string $limiter,
        public string $ip,
        public bool $lockedOut,
        public int $offense,
        public int $tierIndex,
        public int $tierCount,
        public int $hits,
        public int $limit,
        public ?int $windowReset,
        public ?int $lockedUntil,
    ) {
    }

    public function isLockedOut(): bool
    {
        return $this->lockedOut;
    }

    /**
     * Whether any throttling decision is currently in effect for this IP.
     */
    public function isActive(): bool
    {
        return $this->lockedOut || $this->offense > 0;
    }

    /**
     * Whether the IP has a live hit counter but has not offended yet.
     */
    public function isCounting(): bool
    {
        return ! $this->isActive() && $this->hits > 0;
    }

    /**
     * Human-readable summary of the decision's state.
     */
    public function state(): string
    {
        return match (true) {
            $this->lockedOut  => 'locked out',
            $this->offense > 0 => 'tier '.$this->tierIndex,
            $this->hits > 0    => 'counting',
            default            => 'none',
        };
    }
}
