<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Contracts;

use AlbertoArena\Truss\Doctor\FindingCollection;

/**
 * Renders a set of findings to a string for one output target (terminal, JSON,
 * and later CI formats). The command picks one by the --format option.
 */
interface Formatter
{
    public function format(FindingCollection $findings): string;
}
