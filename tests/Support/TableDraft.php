<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Tests\Support;

/**
 * A tiny Blueprint-like helper used by SchemaBuilder to draft one table as the
 * plain array shape SchemaSerializer produces. Native types are written in the
 * MySQL style ("bigint unsigned", "varchar(255)"); rules must read type strings
 * robustly rather than assume one driver. Grow this as new rules need columns.
 */
final class TableDraft
{
    /** @var list<array<string, mixed>> */
    private array $columns = [];

    /** @var list<string> */
    private array $primaryKey = [];

    /** @var list<array<string, mixed>> */
    private array $indexes = [];

    /** @var list<array{column: string, table: string, column_ref: string}> */
    private array $fkDrafts = [];

    private ?string $pendingFk = null;

    /** An auto-incrementing bigint primary key. */
    public function id(string $name = 'id'): self
    {
        $this->column($name, 'bigint unsigned');
        $this->primaryKey = [$name];

        return $this;
    }

    public function string(string $name, int $length = 255): self
    {
        return $this->column($name, "varchar({$length})");
    }

    public function integer(string $name): self
    {
        return $this->column($name, 'int');
    }

    /** A bigint unsigned foreign-key column. Call on() to add the constraint. */
    public function foreignId(string $name): self
    {
        $this->column($name, 'bigint unsigned');
        $this->pendingFk = $name;

        return $this;
    }

    /** Constrain the most recent foreignId() column to a referenced table. */
    public function on(string $table, string $referencedColumn = 'id'): self
    {
        if ($this->pendingFk !== null) {
            $this->foreign($this->pendingFk, $table, $referencedColumn);
            $this->pendingFk = null;
        }

        return $this;
    }

    /** Add a foreign key on an existing column, whatever type it was given. */
    public function foreign(string $column, string $table, string $referencedColumn = 'id'): self
    {
        $this->fkDrafts[] = ['column' => $column, 'table' => $table, 'column_ref' => $referencedColumn];

        return $this;
    }

    public function column(string $name, string $type, bool $nullable = false, ?string $default = null): self
    {
        $this->columns[] = ['name' => $name, 'type' => $type, 'nullable' => $nullable, 'default' => $default];

        return $this;
    }

    /** @param  list<string>  $columns */
    public function primary(array $columns): self
    {
        $this->primaryKey = $columns;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(string $name): array
    {
        return [
            'name' => $name,
            'columns' => $this->columns,
            'primary_key' => $this->primaryKey,
            'indexes' => $this->indexes,
            'foreign_keys' => array_map(
                fn (array $fk): array => [
                    'name' => "{$name}_{$fk['column']}_foreign",
                    'columns' => [$fk['column']],
                    'references_table' => $fk['table'],
                    'references_columns' => [$fk['column_ref']],
                    'on_update' => null,
                    'on_delete' => null,
                ],
                $this->fkDrafts,
            ),
        ];
    }
}
