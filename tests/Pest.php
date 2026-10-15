<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cli\Application;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\FindingCollection;
use AlbertoArena\Truss\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

// Scoped to the directories that hold application-shaped tests rather than to
// tests/, because tests/Cli deliberately gets no Testbench and no application:
// it exercises the container the framework-free binary boots, and applying the
// package TestCase there would test Laravel instead of the CLI.
uses(TestCase::class)->in('Feature', 'Unit');

/**
 * A throwaway SQLite file carrying a small schema, for the CLI tests.
 *
 * A file rather than :memory: because the binary opens its own connection from
 * the string it is handed, and an in-memory database would be a different,
 * empty one. `migrations` is in the packaged `excluded_tables` list, so its
 * presence here is what lets a test prove the exclusions are applied.
 */
function trussCliFixture(): string
{
    // tempnam() creates the file it names, so the suffixed path is a different
    // one: PDO creates that, and the original would leak into the temp
    // directory on every call.
    $base = (string) tempnam(sys_get_temp_dir(), 'truss-cli-');
    $path = $base.'.sqlite';
    @unlink($base);

    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('create table users (id integer primary key, email text not null, created_at datetime)');
    $pdo->exec('create table orders (id integer primary key, user_id integer references users(id), total integer)');
    $pdo->exec('create table migrations (id integer primary key, migration text)');

    return $path;
}

/** A tester for one of the binary's commands. */
function trussCliTester(string $command): CommandTester
{
    return new CommandTester(
        Application::create()->find($command),
    );
}

/**
 * Run one doctor rule and collect its findings as a list, for rule unit tests.
 *
 * @param  array<string, mixed>  $snapshot
 * @return list<Finding>
 */
function doctorCheck(Rule $rule, array $snapshot, string $connection = 'testing'): array
{
    return array_values([...$rule->check($snapshot, $connection)]);
}

/**
 * Normalise whatever a doctor expectation is given (a rule's iterable, a plain
 * array, or a FindingCollection) to a list of findings.
 *
 * @return list<Finding>
 */
function doctorFindingList(mixed $value): array
{
    if ($value instanceof FindingCollection) {
        return $value->all();
    }

    return array_values(is_array($value) ? $value : [...$value]);
}

// expect($findings)->toHaveFinding('TRUSS-INT-001', table: 'logs', column: 'x')
expect()->extend('toHaveFinding', function (string $code, ?string $table = null, ?string $column = null) {
    $matched = false;

    foreach (doctorFindingList($this->value) as $finding) {
        if ($finding->code === $code
            && ($table === null || $finding->table === $table)
            && ($column === null || $finding->column === $column)) {
            $matched = true;
            break;
        }
    }

    $where = $table !== null ? " on {$table}".($column !== null ? ".{$column}" : '') : '';
    expect($matched)->toBeTrue("Expected a {$code} finding{$where}.");

    return $this;
});

// expect($findings)->toBeClean()
expect()->extend('toBeClean', function () {
    expect(doctorFindingList($this->value))->toBe([]);

    return $this;
});
