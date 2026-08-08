<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export;

use AlbertoArena\Truss\Export\Contracts\CommentReader;
use Illuminate\Support\Facades\Schema;

/**
 * Reads native table and column comments through Laravel's schema introspection.
 * MySQL/MariaDB and Postgres expose comments as part of getTables()/getColumns();
 * SQLite and SQL Server have none, so those drivers return an empty map.
 *
 * Structure only: a comment is part of the CREATE TABLE definition, never row
 * data. This is the one DB-touching collaborator behind the Annotator.
 */
final class DatabaseCommentReader implements CommentReader
{
    /**
     * Drivers whose schema comments Laravel introspection surfaces.
     */
    private const SUPPORTED = ['mysql', 'mariadb', 'pgsql'];

    public function read(?string $connection = null): array
    {
        $builder = Schema::connection($connection);

        if (! in_array($builder->getConnection()->getDriverName(), self::SUPPORTED, true)) {
            return [];
        }

        $comments = [];

        foreach ($builder->getTables() as $table) {
            $name = $table['name'];

            if ($this->present($table['comment'] ?? null)) {
                $comments[$name] = (string) $table['comment'];
            }

            foreach ($builder->getColumns($name) as $column) {
                if ($this->present($column['comment'] ?? null)) {
                    $comments["{$name}.{$column['name']}"] = (string) $column['comment'];
                }
            }
        }

        return $comments;
    }

    private function present(mixed $comment): bool
    {
        return $comment !== null && trim((string) $comment) !== '';
    }
}
