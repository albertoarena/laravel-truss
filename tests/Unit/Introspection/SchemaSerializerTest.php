<?php

declare(strict_types=1);

use AlbertoArena\Truss\Introspection\Data\Column;
use AlbertoArena\Truss\Introspection\Data\ForeignKey;
use AlbertoArena\Truss\Introspection\Data\Index;
use AlbertoArena\Truss\Introspection\Data\Table;
use AlbertoArena\Truss\Introspection\SchemaSerializer;

it('serializes a table to the documented array shape', function () {
    $table = new Table(
        name: 'posts',
        columns: [
            new Column('id', 'bigint unsigned', false, null),
            new Column('user_id', 'bigint unsigned', false, null),
            new Column('title', 'varchar(255)', false, null),
        ],
        primaryKey: ['id'],
        indexes: [
            new Index('posts_user_id_index', ['user_id'], false),
        ],
        foreignKeys: [
            new ForeignKey('posts_user_id_foreign', ['user_id'], 'users', ['id'], 'no action', 'cascade'),
        ],
    );

    expect((new SchemaSerializer)->table($table))->toBe([
        'name' => 'posts',
        'columns' => [
            ['name' => 'id', 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null],
            ['name' => 'user_id', 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null],
            ['name' => 'title', 'type' => 'varchar(255)', 'nullable' => false, 'default' => null],
        ],
        'primary_key' => ['id'],
        'indexes' => [
            ['name' => 'posts_user_id_index', 'columns' => ['user_id'], 'unique' => false],
        ],
        'foreign_keys' => [
            [
                'name' => 'posts_user_id_foreign',
                'columns' => ['user_id'],
                'references_table' => 'users',
                'references_columns' => ['id'],
                'on_update' => 'no action',
                'on_delete' => 'cascade',
            ],
        ],
    ]);
});

it('serializes composite keys and a PK-less table', function () {
    $pivot = new Table(
        name: 'post_tag',
        columns: [
            new Column('post_id', 'bigint unsigned', false, null),
            new Column('tag_id', 'bigint unsigned', false, null),
        ],
        primaryKey: ['post_id', 'tag_id'],
        indexes: [],
        foreignKeys: [
            new ForeignKey('post_tag_post_id_foreign', ['post_id'], 'posts', ['id'], 'no action', 'cascade'),
            new ForeignKey('post_tag_tag_id_foreign', ['tag_id'], 'tags', ['id'], 'no action', 'cascade'),
        ],
    );

    $serialized = (new SchemaSerializer)->table($pivot);

    expect($serialized['primary_key'])->toBe(['post_id', 'tag_id'])
        ->and($serialized['foreign_keys'])->toHaveCount(2);

    $keyless = new Table('logs', [new Column('body', 'text', true, null)], [], [], []);

    expect((new SchemaSerializer)->table($keyless)['primary_key'])->toBe([]);
});

it('serializes a set of tables as a list', function () {
    $tables = [
        new Table('users', [new Column('id', 'bigint unsigned', false, null)], ['id'], [], []),
        new Table('posts', [new Column('id', 'bigint unsigned', false, null)], ['id'], [], []),
    ];

    $serialized = (new SchemaSerializer)->tables($tables);

    expect($serialized)->toHaveCount(2)
        ->and($serialized[0]['name'])->toBe('users')
        ->and($serialized[1]['name'])->toBe('posts');
});
