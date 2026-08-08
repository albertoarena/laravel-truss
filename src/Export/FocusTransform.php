<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export;

use InvalidArgumentException;

/**
 * Focus mode: reduce a snapshot to one table and its foreign-key neighbourhood
 * out to `depth` hops. A snapshot transform applied before the generator runs.
 *
 * The neighbourhood is undirected: a table's own foreign keys (child to parent)
 * and any table that references it (parent to child) are both followed, so the
 * result is the connected diagram around the root, not just its outbound edges.
 * A self-referencing foreign key does not add an edge (the table is already the
 * root of its own neighbourhood).
 *
 * This is a deliberate second home for the algorithm in resources/js/selection.js
 * (the interactive dashboard focus cannot round-trip to the server per click).
 * The two are kept in lockstep by a shared fixture, tests/Fixtures/export/
 * focus-cases.json, that both must satisfy. Output order is the input order, so
 * the result is deterministic regardless of traversal order.
 */
final class FocusTransform
{
    /**
     * @param  list<array<string, mixed>>  $tables
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException when the root table is not in the set
     */
    public function apply(array $tables, string $root, int $depth = 1): array
    {
        $present = array_column($tables, 'name');
        if (! in_array($root, $present, true)) {
            throw new InvalidArgumentException("Cannot focus on [{$root}]: no such table in the export set.");
        }

        $neighbours = $this->adjacency($tables);

        $visited = [$root => true];
        $frontier = [$root];
        for ($hop = 0; $hop < $depth; $hop++) {
            $next = [];
            foreach ($frontier as $name) {
                foreach (array_keys($neighbours[$name] ?? []) as $adjacent) {
                    if (! isset($visited[$adjacent])) {
                        $visited[$adjacent] = true;
                        $next[] = $adjacent;
                    }
                }
            }
            if ($next === []) {
                break;
            }
            $frontier = $next;
        }

        return array_values(array_filter($tables, fn (array $table): bool => isset($visited[$table['name']])));
    }

    /**
     * Undirected adjacency built from every foreign key, self-references skipped.
     *
     * @param  list<array<string, mixed>>  $tables
     * @return array<string, array<string, true>>
     */
    private function adjacency(array $tables): array
    {
        $neighbours = [];
        foreach ($tables as $table) {
            $neighbours[$table['name']] = [];
        }

        $link = function (string $a, string $b) use (&$neighbours): void {
            if (isset($neighbours[$a]) && isset($neighbours[$b])) {
                $neighbours[$a][$b] = true;
                $neighbours[$b][$a] = true;
            }
        };

        foreach ($tables as $table) {
            foreach ($table['foreign_keys'] ?? [] as $foreignKey) {
                if ($foreignKey['references_table'] !== $table['name']) {
                    $link($table['name'], $foreignKey['references_table']);
                }
            }
        }

        return $neighbours;
    }
}
