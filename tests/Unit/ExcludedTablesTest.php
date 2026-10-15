<?php

declare(strict_types=1);

use AlbertoArena\Truss\Support\ExcludedTables;

/*
 * The merged exclusion list, global plus per-connection.
 *
 * It exists as one class because the framework-free binary grew a second
 * `show`, and two commands computing "which tables are excluded" from config
 * separately is the drift the parity tests are there to catch. The web payload
 * and the doctor still carry their own copies of this read; moving them is a
 * follow-up, not this change.
 */

it('returns the packaged defaults when nothing is overridden', function (): void {
    // The package ships a real list (framework plumbing: migrations, sessions,
    // cache, the queue tables). Asserting the whole list here would just be a
    // second copy of config/truss.php, so this pins that the defaults arrive
    // at all, which is what a caller depends on.
    expect(ExcludedTables::for('testing'))->toContain('migrations', 'jobs');
});

it('reads the global list', function (): void {
    config(['truss.excluded_tables' => ['jobs', 'sessions']]);

    expect(ExcludedTables::for('testing'))->toBe(['jobs', 'sessions']);
});

it('adds the per-connection list to the global one rather than replacing it', function (): void {
    config([
        'truss.excluded_tables' => ['jobs'],
        'truss.connections.testing.excluded_tables' => ['audit_log'],
    ]);

    expect(ExcludedTables::for('testing'))->toBe(['jobs', 'audit_log']);
});

it('ignores the list of a connection that is not the one asked for', function (): void {
    config([
        'truss.excluded_tables' => [],
        'truss.connections.other.excluded_tables' => ['audit_log'],
    ]);

    expect(ExcludedTables::for('testing'))->toBe([]);
});

it('filters a table list by name', function (): void {
    config(['truss.excluded_tables' => ['jobs']]);

    $tables = [['name' => 'users'], ['name' => 'jobs'], ['name' => 'orders']];

    expect(array_column(ExcludedTables::filter($tables, 'testing'), 'name'))->toBe(['users', 'orders']);
});

it('reindexes after filtering, so the result is a list and not a map', function (): void {
    // The payload and the exports are serialized to JSON, where a gap in the
    // keys turns an array into an object and changes the shape of the output.
    config(['truss.excluded_tables' => ['users']]);

    $filtered = ExcludedTables::filter([['name' => 'users'], ['name' => 'orders']], 'testing');

    expect(array_keys($filtered))->toBe([0]);
});

it('leaves the list alone when nothing is excluded', function (): void {
    $tables = [['name' => 'users'], ['name' => 'orders']];

    expect(ExcludedTables::filter($tables, 'testing'))->toBe($tables);
});
