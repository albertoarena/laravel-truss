<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * An immutable list of findings. The runner is the only place that assembles one;
 * rules just return plain Finding arrays.
 *
 * @implements IteratorAggregate<int, Finding>
 */
final class FindingCollection implements Countable, IteratorAggregate
{
    /** @var list<Finding> */
    private array $findings;

    public function __construct(Finding ...$findings)
    {
        $this->findings = array_values($findings);
    }

    /**
     * A new collection ordered for reports: most severe first, then table name,
     * then rule code, so the output is deterministic regardless of rule order.
     */
    public function sorted(): self
    {
        $findings = $this->findings;

        usort($findings, fn (Finding $a, Finding $b): int => $b->severity->rank() <=> $a->severity->rank()
            ?: $a->table <=> $b->table
            ?: $a->code <=> $b->code);

        return new self(...$findings);
    }

    /**
     * @return list<Finding>
     */
    public function all(): array
    {
        return $this->findings;
    }

    public function isEmpty(): bool
    {
        return $this->findings === [];
    }

    public function count(): int
    {
        return count($this->findings);
    }

    /**
     * @return Traversable<int, Finding>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->findings);
    }
}
