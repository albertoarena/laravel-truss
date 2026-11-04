<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor;

/**
 * An index's key as the doctor rules compare it. For a plain index that is its
 * column list. For an index with an expression it is every key part in order,
 * with each expression as a token no column name can equal, so an index on
 * (tenant_id, lower(email)) is never taken for one on tenant_id: not a
 * duplicate of it, not a prefix of (tenant_id, email), and not a unique key
 * on tenant_id.
 */
final class IndexKey
{
    /**
     * @param  array<string, mixed>  $index
     * @return list<string>
     */
    public static function of(array $index): array
    {
        if (($index['parts'] ?? []) === []) {
            return array_values($index['columns'] ?? []);
        }

        return array_map(
            fn (array $part): string => isset($part['column'])
                ? (string) $part['column']
                : "\0".(string) $part['expression'],
            $index['parts'],
        );
    }

    /**
     * The key for a message: column names and expressions, comma-separated.
     *
     * @param  array<string, mixed>  $index
     */
    public static function describe(array $index): string
    {
        return implode(', ', array_map(fn (string $part): string => ltrim($part, "\0"), self::of($index)));
    }
}
