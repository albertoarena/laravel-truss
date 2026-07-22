<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Introspection;

use AlbertoArena\Truss\Introspection\Data\Column;
use AlbertoArena\Truss\Introspection\Data\ForeignKey;
use AlbertoArena\Truss\Introspection\Data\Index;
use AlbertoArena\Truss\Introspection\Data\Table;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds a schema snapshot for a connection.
 *
 * The primary path introspects the live connection directly via Laravel's native
 * schema methods — no Doctrine DBAL, no static migration parsing. Types are the
 * native full types the database reports; nothing is inferred.
 *
 * This layer knows only about the database. It has no knowledge of HTTP, Blade,
 * caching, or Mermaid, and adds no envelope fields (generated_at) of its own —
 * those belong to the caching layer. See src/Introspection/CLAUDE.md.
 */
class SnapshotBuilder
{
    public function __construct(
        private readonly SchemaSerializer $serializer = new SchemaSerializer,
    ) {}

    /**
     * The name of the disposable in-memory SQLite connection used for fallback.
     */
    private const FALLBACK_CONNECTION = 'truss_sqlite_fallback';

    /**
     * Build the full, serialized snapshot for a connection (default when null).
     *
     * Primary path: introspect the live connection. If that connection is not
     * reachable, fall back to replaying the app's migrations on in-memory
     * SQLite. A failure *after* a successful connection is a real error and is
     * allowed to surface — only connectivity failures trigger the fallback.
     *
     * @return array{connection: string, fallback: bool, skipped_migrations: list<string>, tables: list<array<string, mixed>>}
     */
    public function build(?string $connection = null): array
    {
        $connection ??= (string) config('database.default');

        if (! $this->isReachable($connection)) {
            return $this->buildFromFallback($connection);
        }

        return [
            'connection' => $connection,
            'fallback' => false,
            'skipped_migrations' => [],
            'tables' => $this->serializer->tables($this->introspect($connection)),
        ];
    }

    /**
     * Whether the connection can actually be opened.
     */
    private function isReachable(string $connection): bool
    {
        try {
            Schema::connection($connection)->getConnection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Degraded mode: replay every migrator-resolved path (app + package) on a
     * fresh in-memory SQLite connection, skipping and recording any migration
     * that fails on SQLite, then introspect the result.
     *
     * @return array{connection: string, fallback: bool, skipped_migrations: list<string>, tables: list<array<string, mixed>>}
     */
    private function buildFromFallback(string $requestedConnection): array
    {
        config(['database.connections.'.self::FALLBACK_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        DB::purge(self::FALLBACK_CONNECTION);

        // Mirror how the migrate command resolves paths: custom package/app
        // paths registered via loadMigrationsFrom, plus the default
        // database/migrations directory.
        $migrator = app('migrator');
        $paths = array_unique([...$migrator->paths(), database_path('migrations')]);
        $files = $migrator->getMigrationFiles($paths);

        $skipped = [];
        $previousDefault = config('database.default');

        // Migrations call the Schema facade against the default connection, so
        // swap the default to the fallback for the duration of the replay.
        config(['database.default' => self::FALLBACK_CONNECTION]);
        DB::setDefaultConnection(self::FALLBACK_CONNECTION);

        try {
            foreach ($files as $file) {
                try {
                    $this->resolveMigration($file, $migrator)->up();
                } catch (Throwable) {
                    $skipped[] = basename($file, '.php');
                }
            }

            $tables = $this->introspect(self::FALLBACK_CONNECTION);
        } finally {
            config(['database.default' => $previousDefault]);
            DB::setDefaultConnection($previousDefault);
        }

        return [
            'connection' => $requestedConnection,
            'fallback' => true,
            'skipped_migrations' => $skipped,
            'tables' => $this->serializer->tables($tables),
        ];
    }

    /**
     * Resolve a migration file into a runnable migration instance, mirroring the
     * migrator's own logic (whose resolvePath is protected): anonymous migrations
     * are returned by the file itself; older class-based ones are instantiated
     * from the filename.
     */
    private function resolveMigration(string $file, object $migrator): object
    {
        $migration = require $file;

        if (is_object($migration)) {
            return $migration;
        }

        $name = $migrator->getMigrationName($file);
        $class = Str::studly(implode('_', array_slice(explode('_', $name), 4)));

        return new $class;
    }

    /**
     * Introspect a live connection into typed value objects.
     *
     * @return list<Table>
     */
    public function introspect(string $connection): array
    {
        $builder = Schema::connection($connection);

        return array_map(
            fn (array $table): Table => new Table(
                name: $table['name'],
                columns: $this->columns($builder, $table['name']),
                primaryKey: $this->primaryKey($builder, $table['name']),
                indexes: $this->indexes($builder, $table['name']),
                foreignKeys: $this->foreignKeys($builder, $table['name']),
            ),
            $builder->getTables(),
        );
    }

    /**
     * @return list<Column>
     */
    private function columns(Builder $builder, string $table): array
    {
        return array_map(
            fn (array $column): Column => new Column(
                name: $column['name'],
                type: $column['type'],
                nullable: (bool) $column['nullable'],
                default: $column['default'] !== null ? (string) $column['default'] : null,
            ),
            $builder->getColumns($table),
        );
    }

    /**
     * The primary key hoisted out of the index list (empty when the table has none).
     *
     * @return list<string>
     */
    private function primaryKey(Builder $builder, string $table): array
    {
        foreach ($builder->getIndexes($table) as $index) {
            if ($index['primary'] ?? false) {
                return array_values($index['columns']);
            }
        }

        return [];
    }

    /**
     * Non-primary indexes only; the primary key is reported separately.
     *
     * @return list<Index>
     */
    private function indexes(Builder $builder, string $table): array
    {
        $indexes = [];

        foreach ($builder->getIndexes($table) as $index) {
            if ($index['primary'] ?? false) {
                continue;
            }

            $indexes[] = new Index(
                name: $index['name'],
                columns: array_values($index['columns']),
                unique: (bool) $index['unique'],
            );
        }

        return $indexes;
    }

    /**
     * @return list<ForeignKey>
     */
    private function foreignKeys(Builder $builder, string $table): array
    {
        return array_map(
            fn (array $fk): ForeignKey => new ForeignKey(
                name: (string) ($fk['name'] ?? ''),
                columns: array_values($fk['columns']),
                referencesTable: $fk['foreign_table'],
                referencesColumns: array_values($fk['foreign_columns']),
                onUpdate: $fk['on_update'] !== null && $fk['on_update'] !== '' ? $fk['on_update'] : null,
                onDelete: $fk['on_delete'] !== null && $fk['on_delete'] !== '' ? $fk['on_delete'] : null,
            ),
            $builder->getForeignKeys($table),
        );
    }
}
