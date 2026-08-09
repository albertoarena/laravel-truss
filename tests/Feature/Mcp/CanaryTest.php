<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\SchemaExporter;
use AlbertoArena\Truss\Mcp\Resources\SchemaResource;
use AlbertoArena\Truss\Mcp\Tools\DescribeTable;
use AlbertoArena\Truss\Mcp\Tools\FocusTable;
use AlbertoArena\Truss\Mcp\Tools\GetSchema;
use AlbertoArena\Truss\Mcp\Tools\GetStructuralReview;
use AlbertoArena\Truss\Mcp\Tools\ListTables;
use AlbertoArena\Truss\Mcp\TrussSchemaServer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The structure-only guarantee for the MCP surface: seed a real row with a
 * sentinel, then drive every tool and the resource and assert the sentinel
 * appears in none. The executable form of the promise, now covering MCP.
 */

it('no MCP tool or resource ever leaks row data', function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable()->constrained('users');
        $table->string('token');
    });
    DB::table('users')->insert(['token' => 'TRUSS_ROW_DATA_CANARY']);

    $responses = [
        TrussSchemaServer::tool(ListTables::class),
        TrussSchemaServer::tool(DescribeTable::class, ['table' => 'users']),
        TrussSchemaServer::tool(FocusTable::class, ['table' => 'users']),
        TrussSchemaServer::tool(GetStructuralReview::class),
        TrussSchemaServer::resource(SchemaResource::class),
    ];

    foreach (SchemaExporter::formats() as $format) {
        $responses[] = TrussSchemaServer::tool(GetSchema::class, ['format' => $format]);
    }

    foreach ($responses as $response) {
        $response->assertOk()->assertDontSee('TRUSS_ROW_DATA_CANARY');
    }
});
