<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Support;

/**
 * Which tables config hides, global list plus the connection's own.
 *
 * The cached snapshot is deliberately unfiltered, so that changing the
 * exclusion list needs no rebuild, which makes filtering every caller's job.
 * That worked until there were several callers: the dashboard payload, the
 * doctor, `truss:show`, and now the binary's `show`. The same two config reads
 * copied into each is how the diagram once hid a table while the terminal
 * printed it.
 *
 * So the read lives here once, and the two `show` implementations call it. The
 * dashboard payload and the doctor still carry their own copies; moving them is
 * a follow-up with its own tests rather than a quiet edit made while adding a
 * command.
 */
final class ExcludedTables
{
    /**
     * @return list<string>
     */
    public static function for(string $connection): array
    {
        return [
            ...array_values((array) config('truss.excluded_tables', [])),
            ...array_values((array) config("truss.connections.{$connection}.excluded_tables", [])),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tables
     * @return list<array<string, mixed>>
     */
    public static function filter(array $tables, string $connection): array
    {
        $excluded = self::for($connection);

        if ($excluded === []) {
            return $tables;
        }

        // Reindexed, because these lists are serialized to JSON downstream and
        // a gap in the keys turns an array into an object.
        return array_values(array_filter(
            $tables,
            static fn (array $table): bool => ! in_array($table['name'] ?? null, $excluded, true),
        ));
    }
}
