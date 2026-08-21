# Laravel Truss

Truss reads this application's live database structure: tables, columns, types,
indexes, and foreign keys. It is read only and never queries a row.

Structure only, never data.

Ground a schema task in the real structure instead of reading migration files:

    php artisan truss:export --format=llm --compact

On a large schema, take one table and its foreign-key neighbourhood instead of
the whole thing:

    php artisan truss:export --format=llm --focus=orders --depth=1

Before writing a migration, check the structure for problems (missing primary
keys, unindexed foreign keys, risky column types):

    php artisan truss:doctor

After running one, confirm what it actually changed:

    php artisan truss:diff

Tables and columns may carry business meaning, declared in config or read from
database comments. It is included in exports by default.

Other formats: `--format=dbml|json|csv|markdown|mermaid`. Narrow with
`--connection=`, `--tables=`, `--exclude=`. Write a file with `--output=`, and
`--check` exits non-zero when that file is out of date.

If you can run tinker but not shell commands:
`Truss::snapshot()->focus('orders')->compact()->toLlm()`.

A dedicated Truss MCP server is optionally available (`composer require
laravel/mcp`, then `php artisan mcp:start truss`). It is not required for
anything above.

Truss never returns row data. Do not reach for it to inspect records.
