<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\CsvGenerator;
use AlbertoArena\Truss\Export\JsonGenerator;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

function jsonExport(array $tables): string
{
    return (new JsonGenerator)->generate($tables);
}

function csvExport(array $tables): string
{
    return (new CsvGenerator)->generate($tables);
}

it('emits the whole schema as a JSON array in the serializer shape', function () {
    $tables = SchemaBuilder::make()->table('users', function ($t) {
        $t->id();
        $t->string('name');
    })->build()['tables'];

    $decoded = json_decode(jsonExport($tables), true);

    expect($decoded)->toBeArray()->toHaveCount(1)
        ->and($decoded[0]['name'])->toBe('users')
        ->and($decoded[0]['columns'][0])->toMatchArray(['name' => 'id', 'type' => 'bigint unsigned'])
        ->and($decoded[0])->toHaveKeys(['name', 'columns', 'primary_key', 'indexes', 'foreign_keys']);
});

it('renders an empty schema as an empty JSON array', function () {
    expect(jsonExport([]))->toBe('[]');
});

it('emits a CSV header and one row per column with a table column', function () {
    $tables = SchemaBuilder::make()
        ->table('users', function ($t) {
            $t->id();
            $t->string('name');
        })
        ->table('posts', function ($t) {
            $t->id();
            $t->foreignId('user_id')->on('users');
        })->build()['tables'];

    $csv = csvExport($tables);
    $lines = explode("\n", $csv);

    expect($lines[0])->toBe('table,name,type,nullable,default,key')
        ->and($lines)->toHaveCount(1 + 2 + 2); // header + users(2) + posts(2)
    expect($csv)->toContain('users,id,bigint unsigned,false,,PK')
        ->and($csv)->toContain('posts,user_id,bigint unsigned,false,,FK');
});

it('renders nullable as true/false and blanks a null default', function () {
    $tables = SchemaBuilder::make()->table('t', function ($t) {
        $t->column('a', 'int', nullable: true);
    })->build()['tables'];

    expect(csvExport($tables))->toContain('t,a,int,true,,');
});

it('quotes a CSV cell containing a comma and doubles inner quotes', function () {
    $tables = SchemaBuilder::make()->table('t', function ($t) {
        $t->column('c', "enum('a','b')");
    })->build()['tables'];

    // The type has commas, so the whole cell is quoted verbatim.
    expect(csvExport($tables))->toContain('"enum(\'a\',\'b\')"');
});
