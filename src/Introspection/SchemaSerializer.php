<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Introspection;

use AlbertoArena\Truss\Introspection\Data\Column;
use AlbertoArena\Truss\Introspection\Data\ForeignKey;
use AlbertoArena\Truss\Introspection\Data\Index;
use AlbertoArena\Truss\Introspection\Data\Table;

/**
 * The serialization boundary: turns typed value objects into the plain, ordered
 * array shape documented in docs/DESIGN.md. This is the only place that knows
 * the wire shape — the builder never assembles arrays itself.
 *
 * No envelope fields (connection, generated_at) are added here; those belong to
 * the caching layer, keeping this layer pure and deterministic.
 */
class SchemaSerializer
{
    /**
     * @param  list<Table>  $tables
     * @return list<array<string, mixed>>
     */
    public function tables(array $tables): array
    {
        return array_map($this->table(...), $tables);
    }

    /**
     * @return array<string, mixed>
     */
    public function table(Table $table): array
    {
        return [
            'name' => $table->name,
            'columns' => array_map($this->column(...), $table->columns),
            'primary_key' => array_values($table->primaryKey),
            'indexes' => array_map($this->index(...), $table->indexes),
            'foreign_keys' => array_map($this->foreignKey(...), $table->foreignKeys),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function column(Column $column): array
    {
        return [
            'name' => $column->name,
            'type' => $column->type,
            'nullable' => $column->nullable,
            'default' => $column->default,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function index(Index $index): array
    {
        return [
            'name' => $index->name,
            'columns' => array_values($index->columns),
            'unique' => $index->unique,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function foreignKey(ForeignKey $foreignKey): array
    {
        return [
            'name' => $foreignKey->name,
            'columns' => array_values($foreignKey->columns),
            'references_table' => $foreignKey->referencesTable,
            'references_columns' => array_values($foreignKey->referencesColumns),
            'on_update' => $foreignKey->onUpdate,
            'on_delete' => $foreignKey->onDelete,
        ];
    }
}
