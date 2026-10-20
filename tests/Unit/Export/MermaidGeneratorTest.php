<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\MermaidGenerator;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

/**
 * PHP port of the native-mode path of resources/js/mermaid-definition.js,
 * mirroring tests/js/mermaid-definition.test.js. Byte parity is pinned by the
 * cross-check test.
 */
function mermaid(array $tables): string
{
    return (new MermaidGenerator)->generate($tables);
}

it('opens with the erDiagram keyword and an entity block per table', function () {
    $tables = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('posts', function ($t) {
            $t->id();
            $t->foreignId('user_id')->on('users');
        })->build()['tables'];

    $out = mermaid($tables);

    expect(str_starts_with($out, 'erDiagram'))->toBeTrue()
        ->and($out)->toContain('users {')
        ->and($out)->toContain('posts {');
});

it('derives PK and FK badges from primary_key and foreign_keys', function () {
    $tables = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('posts', function ($t) {
            $t->id();
            $t->foreignId('user_id')->on('users');
        })->build()['tables'];

    $out = mermaid($tables);

    expect($out)->toMatch('/id\s+PK/')
        ->and($out)->toMatch('/user_id\s+FK/');
});

it('marks a column that is both primary and foreign with PK, FK', function () {
    $tables = SchemaBuilder::make()
        ->table('roles', fn ($t) => $t->id())
        ->table('users', fn ($t) => $t->id())
        ->table('role_user', function ($t) {
            $t->foreignId('role_id')->on('roles');
            $t->foreignId('user_id')->on('users');
            $t->primary(['role_id', 'user_id']);
        })->build()['tables'];

    $out = mermaid($tables);

    expect($out)->toMatch('/role_id\s+PK,\s*FK/')
        ->and($out)->toMatch('/user_id\s+PK,\s*FK/');
});

it('sanitizes native types into Mermaid-safe tokens (no spaces or parens)', function () {
    $tables = SchemaBuilder::make()->table('posts', function ($t) {
        $t->id();
        $t->string('title');
    })->build()['tables'];

    $out = mermaid($tables);

    expect($out)->not->toMatch('/bigint unsigned/')
        ->and($out)->not->toMatch('/varchar\(255\)/')
        ->and($out)->toContain('bigint_unsigned')
        ->and($out)->toContain('varchar_255');
});

it('compacts enum/set to the keyword so long value lists do not blow out the column', function () {
    $tables = SchemaBuilder::make()->table('posts', function ($t) {
        $t->id();
        $t->column('status', "enum('draft','published','archived')");
    })->build()['tables'];

    $out = mermaid($tables);

    expect($out)->toMatch('/enum\s+status/')
        ->and($out)->not->toContain('draft')
        ->and($out)->not->toContain('archived');
});

it('emits a crow\'s-foot relationship for a foreign key within the subset', function () {
    $tables = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('posts', function ($t) {
            $t->id();
            $t->foreignId('user_id')->on('users');
        })->build()['tables'];

    $out = mermaid($tables);

    expect($out)->toContain('users ||--o{ posts')
        ->and($out)->toContain('posts_user_id_foreign');
});

it('omits relationships whose referenced table is not in the subset', function () {
    $tables = SchemaBuilder::make()->table('posts', function ($t) {
        $t->id();
        $t->foreignId('user_id')->on('users');
    })->build()['tables'];

    $out = mermaid($tables);

    expect($out)->not->toContain('||--o{')
        ->and($out)->not->toContain('users {');
});

it('shows a self-referential foreign key as a column note, not a self-loop edge', function () {
    $tables = SchemaBuilder::make()->table('categories', function ($t) {
        $t->id();
        $t->foreignId('parent_id')->on('categories');
    })->build()['tables'];

    $out = mermaid($tables);

    expect($out)->not->toContain('categories ||--o{ categories')
        ->and($out)->toMatch('/parent_id\s+FK\s+"self-ref"/');
});

// Issue #94: a name Mermaid's ER grammar rejects used to reach the definition
// verbatim and fail the whole diagram. Names are rewritten only when Mermaid
// would reject them or record something else; tests/js/mermaid-identifiers.test.js
// pins the rule to real Mermaid, and parses the fixtures pinned here.

/**
 * @param  list<array{0: string, 1?: string}>  $columns  name and type
 * @param  list<array<string, mixed>>  $foreignKeys
 * @param  list<string>  $primaryKey
 * @return array<string, mixed>
 */
function awkwardTable(string $name, array $columns, array $foreignKeys = [], array $primaryKey = []): array
{
    return [
        'name' => $name,
        'columns' => array_map(fn (array $c): array => [
            'name' => $c[0], 'type' => $c[1] ?? 'int', 'nullable' => false, 'default' => null,
        ], $columns),
        'primary_key' => $primaryKey,
        'indexes' => [],
        'foreign_keys' => $foreignKeys,
    ];
}

/** @return array<string, mixed> */
function awkwardFk(string $name, array $columns, string $referencesTable): array
{
    return [
        'name' => $name, 'columns' => $columns, 'references_table' => $referencesTable,
        'references_columns' => ['id'], 'on_update' => null, 'on_delete' => null,
    ];
}

it('keeps the output of a schema that renders today byte for byte', function () {
    $tables = SchemaBuilder::make()
        ->table('categories', function ($t) {
            $t->id();
            $t->column('parent_id', 'bigint unsigned', nullable: true);
            $t->foreign('parent_id', 'categories');
        })
        ->table('posts', function ($t) {
            $t->id();
            $t->foreignId('user_id')->on('users');
            $t->string('title');
            $t->column('status', "enum('draft','published','archived')");
            $t->column('published_at', 'timestamp', nullable: true);
        })
        ->table('role_user', function ($t) {
            $t->foreignId('role_id')->on('roles');
            $t->foreignId('user_id')->on('users');
            $t->primary(['role_id', 'user_id']);
        })
        ->table('roles', function ($t) {
            $t->id();
            $t->string('name');
        })
        ->table('users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email');
        })->build()['tables'];

    expect(mermaid($tables))->toBe(file_get_contents(dirname(__DIR__, 2).'/Fixtures/export/schema.mmd'));
});

it('rewrites a column name Mermaid rejects and keeps the real name in the comment', function (string $name, string $line) {
    expect(mermaid([awkwardTable('t', [[$name]])]))->toContain("\n{$line}\n");
})->with([
    'leading digit' => ['429_error_date', '    int _429_error_date "429_error_date"'],
    'space' => ['full name', '    int full_name "full name"'],
    'question mark' => ['name?', '    int name_ "name?"'],
    'dollar' => ['a$b', '    int a_b "a$b"'],
    'pound sign' => ['£', '    int _ "£"'],
    'key keyword' => ['pk', '    int _pk "pk"'],
    'key keyword, upper case' => ['UK', '    int _UK "UK"'],
    'key keyword at a word boundary' => ['pk-x', '    int _pk-x "pk-x"'],
    'leading hyphen' => ['-lead', '    int _-lead "-lead"'],
    'backticks' => ['`tick`', '    int _tick_ "`tick`"'],
    'one tilde' => ['a~b', '    int a_b "a#126;b"'],
    'a quote' => ['a"b', '    int a_b "a#34;b"'],
    'zero, which PHP reads as falsy' => ['0', '    int _0 "0"'],
]);

it('passes a column name Mermaid accepts through untouched', function (string $name) {
    expect(mermaid([awkwardTable('t', [[$name]])]))->toContain("\n    int {$name}\n");
})->with([
    'città', 'prezzo_€', 'naïve_col', 'größe', '名前', 'col😀', 'µs', 'ſ', 'K',
    '_429_error_date', 'my-col', 'x-pk', 'PK_id', 'pk_x', 'a.b', 'a,b', 'a(b)',
    'a~b~c', 'x~ ~', '*star',
]);

it('aliases a table name Mermaid rejects under its real name', function (string $name, string $head) {
    expect(mermaid([awkwardTable($name, [['id']])]))->toContain("\n  {$head} {\n");
})->with([
    'leading digit' => ['429_logs', 't_429_logs["429_logs"]'],
    'space' => ['a b', 't_a_b["a b"]'],
    'grammar keyword' => ['style', 't_style["style"]'],
    'keyword, upper case' => ['ONE', 't_ONE["ONE"]'],
    'keyword at a word boundary' => ['one-x', 't_one-x["one-x"]'],
    'cardinality operator' => ['u-x', 't_u-x["u-x"]'],
    'label-breaking characters' => ['50% "off"', 't_50___off_["50#37; #34;off#34;"]'],
    'backslash' => ['a\\b', 't_a_b["a#92;b"]'],
]);

it('passes a table name Mermaid accepts through untouched', function (string $name) {
    expect(mermaid([awkwardTable($name, [['id']])]))->toContain("\n  {$name} {\n");
})->with(['429', '12.5', 'my.table', 'my--table', '名前', 'città', 'only', 'u', 'pk', 'style_x', 'oneself']);

it('deduplicates colliding table ids in table order without taking a real name', function () {
    $out = mermaid([
        awkwardTable('a$b', [['id']]),
        awkwardTable('t_a_b', [['id']]),
        awkwardTable('a b', [['id']]),
    ]);

    expect($out)->toContain("\n  t_a_b_2[\"a\$b\"] {\n")
        ->and($out)->toContain("\n  t_a_b {\n")
        ->and($out)->toContain("\n  t_a_b_3[\"a b\"] {\n");
});

it('renders a schema of awkward names exactly as the committed fixture Vitest parses', function () {
    $tables = [
        awkwardTable('users', [['id'], ['429_error_date', 'date'], ['pk'], ['full name'], ['a ~b']], primaryKey: ['id']),
        awkwardTable('429_logs', [['id'], ['user_id']], [awkwardFk('fk "quoted"', ['user_id'], 'users')]),
        awkwardTable('a$b', [['id'], ['a$b'], ['a_b']]),
        awkwardTable('style', [['id'], ['parent_id'], ['fk']], [
            awkwardFk('style_parent', ['parent_id'], 'style'),
            awkwardFk('style_fk', ['fk'], 'style'),
            awkwardFk('style_ab', ['id'], 'a$b'),
        ]),
        awkwardTable('50% "off"', [['id']]),
    ];

    expect(mermaid($tables))->toBe(file_get_contents(dirname(__DIR__, 2).'/Fixtures/export/awkward-names.mmd'));
});

/**
 * Every case in mermaid-name-cases.json as one schema: the rejected columns of
 * one table, the accepted ones of another, and each table name as its own table
 * with an edge (a leading digit only fails once an edge references it).
 *
 * @return list<array<string, mixed>>
 */
function nameCaseTables(): array
{
    $cases = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/export/mermaid-name-cases.json'), true);
    $columns = fn (array $names): array => array_map(fn (string $name): array => [$name], $names);

    return [
        awkwardTable('users', [['id']], primaryKey: ['id']),
        awkwardTable('columns_rejected', $columns($cases['columns']['rejected'])),
        awkwardTable('columns_accepted', $columns($cases['columns']['accepted'])),
        ...array_map(
            fn (string $name): array => awkwardTable($name, [['user_id']], [awkwardFk("{$name}_user", ['user_id'], 'users')]),
            [...$cases['tables']['rejected'], ...$cases['tables']['accepted']],
        ),
    ];
}

it('renders every name case exactly as the committed fixture Vitest parses', function () {
    expect(mermaid(nameCaseTables()))
        ->toBe(file_get_contents(dirname(__DIR__, 2).'/Fixtures/export/mermaid-name-cases.mmd'));
});

// Review follow-ups: Unicode whitespace, a trailing newline (PCRE `$` matches
// before one unless D is set), a carriage return (PCRE `.` matches it, the
// JavaScript lexer's does not), and the `direction TB` layout directive.

it('rewrites the shared readable tokens exactly as the dashboard fallback does', function () {
    $cases = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/export/mermaid-name-cases.json'), true);
    unset($cases['rewrites']['_comment']);

    foreach ($cases['rewrites'] as $name => $token) {
        expect(mermaid([awkwardTable('t', [[(string) $name]])]))->toContain("\n    int {$token} \"");
    }
});

it('does not pass through a name with leading Unicode whitespace or a trailing newline', function (string $name) {
    expect(mermaid([awkwardTable('t', [[$name]])]))->not->toContain("\n    int {$name}")
        ->and(mermaid([awkwardTable($name, [['id']])]))->not->toContain("\n  {$name} {");
})->with([
    'no-break space' => ["\u{A0}x"],
    'ideographic space' => ["\u{3000}"],
    'byte order mark' => ["\u{FEFF}id"],
    'trailing newline' => ["id\n"],
]);

it('reads a carriage return as the JavaScript lexer does', function () {
    expect(mermaid([awkwardTable('t', [["a~\r~b"]])]))->toContain("\n    int a___b \"a#126; #126;b\"\n");
});

it('keeps the direction directive out of an alias and a relationship label', function () {
    $out = mermaid([
        awkwardTable('users', [['id']]),
        awkwardTable('sort direction LR', [['user_id']], [awkwardFk('by direction tb', ['user_id'], 'users')]),
    ]);

    expect($out)->toContain('t_sort_direction_LR["sort d#105;rection LR"] {')
        ->and($out)->toContain(': "by d#105;rection tb"');
});

it('renders every fuzz name exactly as the committed fixture Vitest parses', function () {
    $fuzz = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/export/mermaid-name-fuzz.json'), true);
    $tables = [
        awkwardTable('users', [['id']], primaryKey: ['id']),
        awkwardTable('columns', array_map(fn (string $name): array => [$name], $fuzz['names'])),
        ...array_map(
            fn (string $name): array => awkwardTable($name, [['user_id']], [awkwardFk('fk', ['user_id'], 'users')]),
            $fuzz['names'],
        ),
    ];

    expect(mermaid($tables))
        ->toBe(file_get_contents(dirname(__DIR__, 2).'/Fixtures/export/mermaid-name-fuzz.mmd'));
});

it('passes a fuzz name through exactly when Mermaid records it verbatim', function () {
    $fuzz = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/export/mermaid-name-fuzz.json'), true);

    foreach ($fuzz['names'] as $i => $name) {
        $column = str_contains(mermaid([awkwardTable('t', [[$name]])]), "\n    int {$name}\n");
        $table = str_contains(mermaid([awkwardTable($name, [['id']])]), "\n  {$name} {\n");

        expect([$column, $table])->toBe([$fuzz['columns'][$i], $fuzz['tables'][$i]], json_encode($name));
    }
});
