<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Mcp;

use Composer\InstalledVersions;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;

/**
 * The read-only, structure-only Truss MCP server. It exposes the live database
 * structure to a coding agent as MCP tools and a resource, so the agent can
 * query the current schema on demand instead of being handed a stale paste.
 *
 * Every tool reads the same cached snapshot the dashboard uses, strips
 * `excluded_tables`, honours the managed-connection allow-list, and returns
 * structure only, never row data. The server never calls a model; it answers
 * with structure, it does not reason with one.
 */
class TrussSchemaServer extends Server
{
    protected string $name = 'Truss';

    protected string $instructions = <<<'MARKDOWN'
        Truss exposes this Laravel application's live database structure: tables,
        columns, indexes, and foreign keys, plus any annotations the maintainers
        declared. It is structure only and read only: it never returns row data,
        never writes, and never runs a query you supply. It describes what exists,
        not what the data means beyond the annotations. Use it to ground your work
        in the real schema instead of guessing column and table names.
        MARKDOWN;

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        Tools\ListTables::class,
        Tools\DescribeTable::class,
        Tools\GetSchema::class,
        Tools\FocusTable::class,
        Tools\GetStructuralReview::class,
    ];

    /**
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        Resources\SchemaResource::class,
    ];

    protected function boot(): void
    {
        $this->version = InstalledVersions::getPrettyVersion('albertoarena/laravel-truss') ?? 'dev';
    }
}
