<?php

declare(strict_types=1);

use AlbertoArena\Truss\Mcp\Resources\SchemaResource;
use AlbertoArena\Truss\Mcp\TrussSchemaServer;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('users', fn ($table) => $table->id());
    Schema::create('posts', function ($table) {
        $table->id();
        $table->foreignId('user_id')->constrained();
        $table->string('status')->default('draft');
    });
});

it('the schema resource returns the whole structure, compact', function () {
    TrussSchemaServer::resource(SchemaResource::class)
        ->assertOk()
        ->assertSee('users')
        ->assertSee('posts')
        // Compact: the default is dropped.
        ->assertDontSee('default:');
});

it('the schema resource never includes an excluded table', function () {
    config()->set('truss.excluded_tables', ['posts']);

    TrussSchemaServer::resource(SchemaResource::class)
        ->assertOk()
        ->assertSee('users')
        ->assertDontSee('Table posts {');
});

it('the schema resource is published under the truss://schema uri', function () {
    expect((new SchemaResource)->uri())->toBe('truss://schema');
});
