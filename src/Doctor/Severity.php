<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor;

/**
 * How serious a finding is. The string value is the token used in config and in
 * the `--fail-on` option; `rank()` gives the sort and threshold order.
 */
enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';

    /**
     * Higher is more severe. Used to sort findings and to compare against a
     * `--fail-on` threshold. Never rely on enum declaration order for this.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Error => 3,
            self::Warning => 2,
            self::Info => 1,
        };
    }

    /**
     * Whether this severity is at or above a threshold, for `--fail-on` gating.
     */
    public function meetsOrExceeds(self $threshold): bool
    {
        return $this->rank() >= $threshold->rank();
    }
}
