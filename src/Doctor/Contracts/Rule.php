<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Contracts;

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;

/**
 * A single structural check. A rule is pure: it receives a serialized snapshot
 * (the shape SchemaSerializer produces) plus the connection name, and returns
 * Findings. It never reads config, suppressions, or output; severity overrides,
 * ignore patterns, and ordering are the runner's job.
 */
interface Rule
{
    /** Stable machine identifier, e.g. "TRUSS-IDX-001". */
    public function code(): string;

    public function category(): Category;

    public function confidence(): Confidence;

    public function defaultSeverity(): Severity;

    /** One line, shown in reports. */
    public function title(): string;

    /**
     * @param  array<string, mixed>  $snapshot
     * @return iterable<Finding>
     */
    public function check(array $snapshot, string $connection): iterable;
}
