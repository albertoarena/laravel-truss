<?php

declare(strict_types=1);

use AlbertoArena\Truss\Diff\DiffRenderer;

/*
 * The body of a diff report, as lines.
 *
 * Extracted from DiffCommand because the binary grew a second diff, and 110
 * lines of formatting copied into it would be the drift the parity tests exist
 * to catch. Both commands now render through here and differ only in the
 * sentence above the body, which they should: one answers "what changed since
 * the last migration" and the other "what differs between these two
 * databases".
 *
 * Pure: a diff array in, lines out. No connection, no container, no output.
 */

function emptyTableDiff(string $name): array
{
    return [
        'name' => $name,
        'columns_added' => [], 'columns_removed' => [], 'columns_changed' => [],
        'indexes_added' => [], 'indexes_removed' => [], 'indexes_changed' => [],
        'foreign_keys_added' => [], 'foreign_keys_removed' => [], 'foreign_keys_changed' => [],
        'changes' => [],
    ];
}

function diffOf(array $overrides = []): array
{
    return [
        'tables_added' => [],
        'tables_removed' => [],
        'tables_changed' => [],
        'has_changes' => true,
        ...$overrides,
    ];
}

it('says nothing at all when nothing changed', function (): void {
    // The caller decides what to print instead, because the sentence differs
    // between the two surfaces.
    expect(DiffRenderer::body(diffOf(['has_changes' => false])))->toBe([]);
});

it('lists added and removed tables with their markers', function (): void {
    $lines = DiffRenderer::body(diffOf([
        'tables_added' => [['name' => 'invoices']],
        'tables_removed' => [['name' => 'legacy_import']],
    ]));

    expect($lines)->toContain('<comment>Added tables:</comment>')
        ->and($lines)->toContain('  + invoices')
        ->and($lines)->toContain('<comment>Removed tables:</comment>')
        ->and($lines)->toContain('  - legacy_import');
});

it('omits a section that has nothing in it', function (): void {
    $lines = DiffRenderer::body(diffOf(['tables_added' => [['name' => 'invoices']]]));

    expect(implode("\n", $lines))->not->toContain('Removed tables')
        ->and(implode("\n", $lines))->not->toContain('Changed tables');
});

it('describes a column added, removed and changed', function (): void {
    $table = [...emptyTableDiff('users'),
        'columns_added' => [['name' => 'nickname', 'type' => 'varchar']],
        'columns_removed' => [['name' => 'fax']],
        'columns_changed' => [['name' => 'email', 'changes' => ['nullable' => ['before' => true, 'after' => false]]]],
    ];

    $lines = DiffRenderer::body(diffOf(['tables_changed' => [$table]]));

    expect($lines)->toContain('  ~ users')
        ->and($lines)->toContain('      column added: nickname (varchar)')
        ->and($lines)->toContain('      column removed: fax')
        ->and($lines)->toContain('      column changed: email (nullable: true -> false)');
});

it('describes index and foreign key movement', function (): void {
    $table = [...emptyTableDiff('orders'),
        'indexes_added' => [['name' => 'orders_user_id_index']],
        'indexes_removed' => [['name' => 'orders_old_index']],
        'foreign_keys_added' => [['name' => 'orders_user_id_foreign']],
    ];

    $lines = DiffRenderer::body(diffOf(['tables_changed' => [$table]]));

    expect($lines)->toContain('      index added: orders_user_id_index')
        ->and($lines)->toContain('      index removed: orders_old_index')
        ->and($lines)->toContain('      foreign key added: orders_user_id_foreign');
});

it('renders a primary key change as before and after', function (): void {
    $table = [...emptyTableDiff('pivot'),
        'changes' => ['primary_key' => ['before' => ['id'], 'after' => ['user_id', 'role_id']]],
    ];

    $lines = DiffRenderer::body(diffOf(['tables_changed' => [$table]]));

    expect($lines)->toContain('      primary key: [id] -> [user_id, role_id]');
});

it('renders the awkward scalars rather than printing an empty string', function (mixed $before, mixed $after, string $expected): void {
    // A default going from null to a value, or a boolean flipping, is the most
    // common change there is, and "default:  -> " tells the reader nothing.
    $table = [...emptyTableDiff('users'),
        'columns_changed' => [['name' => 'flag', 'changes' => ['default' => ['before' => $before, 'after' => $after]]]],
    ];

    expect(DiffRenderer::body(diffOf(['tables_changed' => [$table]])))
        ->toContain("      column changed: flag (default: {$expected})");
})->with([
    'null to value' => [null, '0', 'null -> 0'],
    'boolean flip' => [true, false, 'true -> false'],
    'list' => [['a'], ['a', 'b'], '[a] -> [a, b]'],
]);
