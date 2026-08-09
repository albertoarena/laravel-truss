<?php

declare(strict_types=1);

use AlbertoArena\Truss\Mcp\Tools\DescribeTable;
use AlbertoArena\Truss\Mcp\Tools\FocusTable;
use AlbertoArena\Truss\Mcp\Tools\GetSchema;
use AlbertoArena\Truss\Mcp\Tools\GetStructuralReview;
use AlbertoArena\Truss\Mcp\Tools\ListTables;
use AlbertoArena\Truss\Mcp\TrussSchemaServer;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('email')->unique();
    });
    Schema::create('posts', function ($table) {
        $table->id();
        $table->foreignId('user_id')->constrained();
        $table->string('title');
    });
});

it('list_tables lists each table with a structural summary', function () {
    TrussSchemaServer::tool(ListTables::class)
        ->assertOk()
        ->assertSee('users')
        ->assertSee('posts')
        ->assertSee('columns');
});

it('list_tables never includes an excluded table', function () {
    config()->set('truss.excluded_tables', ['posts']);

    TrussSchemaServer::tool(ListTables::class)
        ->assertOk()
        ->assertSee('users')
        ->assertDontSee('posts');
});

it('list_tables errors on an unmanaged connection', function () {
    TrussSchemaServer::tool(ListTables::class, ['connection' => 'nope'])
        ->assertHasErrors();
});

it('describe_table returns the columns, keys, and foreign keys of one table', function () {
    TrussSchemaServer::tool(DescribeTable::class, ['table' => 'posts'])
        ->assertOk()
        ->assertSee('user_id')
        ->assertSee('title')
        ->assertSee('foreign_keys');
});

it('describe_table errors on a missing or excluded table', function () {
    config()->set('truss.excluded_tables', ['posts']);

    TrussSchemaServer::tool(DescribeTable::class, ['table' => 'ghost'])->assertHasErrors();
    TrussSchemaServer::tool(DescribeTable::class, ['table' => 'posts'])->assertHasErrors();
});

it('get_schema returns the full export in the requested format', function () {
    TrussSchemaServer::tool(GetSchema::class, ['format' => 'dbml'])
        ->assertOk()
        ->assertSee('Table posts {')
        ->assertSee('Table users {');
});

it('get_schema honours a table filter and compact', function () {
    TrussSchemaServer::tool(GetSchema::class, ['format' => 'dbml', 'tables' => ['users'], 'compact' => true])
        ->assertOk()
        ->assertSee('Table users {')
        ->assertDontSee('Table posts {');
});

it('get_schema rejects an unknown format', function () {
    TrussSchemaServer::tool(GetSchema::class, ['format' => 'yaml'])->assertHasErrors();
});

it('focus_table returns the table and its foreign-key neighbourhood', function () {
    TrussSchemaServer::tool(FocusTable::class, ['table' => 'posts', 'depth' => 1])
        ->assertOk()
        ->assertSee('Table posts {')
        ->assertSee('Table users {');
});

it('focus_table errors on a missing table', function () {
    TrussSchemaServer::tool(FocusTable::class, ['table' => 'ghost'])->assertHasErrors();
});

it('get_structural_review returns the deterministic findings payload', function () {
    TrussSchemaServer::tool(GetStructuralReview::class)
        ->assertOk()
        ->assertSee('summary')
        ->assertSee('findings');
});

it('get_structural_review errors on an unmanaged connection', function () {
    TrussSchemaServer::tool(GetStructuralReview::class, ['connection' => 'nope'])->assertHasErrors();
});
