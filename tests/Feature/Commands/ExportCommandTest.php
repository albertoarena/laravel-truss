<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\SchemaExporter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('posts', function ($table) {
        $table->id();
        $table->foreignId('user_id')->constrained();
        $table->string('title');
    });

    // A unique path that does not yet exist; --output creates it.
    $this->tmp = sys_get_temp_dir().'/truss-export-'.bin2hex(random_bytes(6)).'.out';
});

afterEach(function () {
    if (isset($this->tmp) && is_file($this->tmp)) {
        unlink($this->tmp);
    }
});

it('defaults to DBML on stdout and exits 0', function () {
    // Artisan::output() captures the raw multi-line body written to stdout, which
    // the line-oriented expectsOutput matcher does not handle for a bare write().
    $code = Artisan::call('truss:export');
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('Table posts {')
        ->and($out)->toContain('Ref: posts.user_id > users.id');
});

it('exports JSON with --format=json', function () {
    $this->artisan('truss:export', ['--format' => 'json', '--output' => $this->tmp])
        ->assertExitCode(0);

    $decoded = json_decode((string) file_get_contents($this->tmp), true);
    expect(collect($decoded)->pluck('name')->all())->toBe(['posts', 'users']); // alphabetical
});

it('rejects an unknown format with exit code 2', function () {
    $this->artisan('truss:export', ['--format' => 'yaml'])->assertExitCode(2);
});

it('keeps only the --tables allowlist', function () {
    $this->artisan('truss:export', ['--tables' => 'users', '--output' => $this->tmp])
        ->assertExitCode(0);

    $out = (string) file_get_contents($this->tmp);
    expect($out)->toContain('Table users {')->not->toContain('Table posts {');
});

it('drops the --exclude blocklist', function () {
    $this->artisan('truss:export', ['--exclude' => 'posts', '--output' => $this->tmp])
        ->assertExitCode(0);

    $out = (string) file_get_contents($this->tmp);
    expect($out)->toContain('Table users {')->not->toContain('Table posts {');
});

it('never exports a config-excluded table, even via --tables', function () {
    config(['truss.excluded_tables' => ['posts']]);

    $this->artisan('truss:export', ['--tables' => 'posts,users', '--output' => $this->tmp])
        ->assertExitCode(0);

    $out = (string) file_get_contents($this->tmp);
    expect($out)->toContain('Table users {')->not->toContain('Table posts {');
});

it('exits 2 when nothing matches the filters', function () {
    $this->artisan('truss:export', ['--tables' => 'does_not_exist'])->assertExitCode(2);
});

it('writes a file with --output and confirms', function () {
    $this->artisan('truss:export', ['--output' => $this->tmp])
        ->expectsOutputToContain("Wrote dbml export to {$this->tmp}.")
        ->assertExitCode(0);

    expect(file_get_contents($this->tmp))->toContain('Table posts {');
});

it('--check exits 0 when the file is up to date', function () {
    $this->artisan('truss:export', ['--output' => $this->tmp])->assertExitCode(0);

    $before = (string) file_get_contents($this->tmp);

    $this->artisan('truss:export', ['--output' => $this->tmp, '--check' => true])
        ->assertExitCode(0);

    // --check writes nothing.
    expect(file_get_contents($this->tmp))->toBe($before);
});

it('--check exits 1 on drift and writes nothing', function () {
    file_put_contents($this->tmp, "stale contents\n");

    $this->artisan('truss:export', ['--output' => $this->tmp, '--check' => true])
        ->assertExitCode(1);

    expect(file_get_contents($this->tmp))->toBe("stale contents\n");
});

it('--check exits 1 when the target file does not exist', function () {
    // $this->tmp is a fresh, not-yet-created path.
    $this->artisan('truss:export', ['--output' => $this->tmp, '--check' => true])
        ->assertExitCode(1);

    expect(is_file($this->tmp))->toBeFalse();
});

it('--check without --output is a usage error (exit 2)', function () {
    $this->artisan('truss:export', ['--check' => true])->assertExitCode(2);
});

it('rejects an unmanaged connection with exit 2', function () {
    $this->artisan('truss:export', ['--connection' => 'nope'])->assertExitCode(2);
});

it('exports a fresh snapshot with --fresh', function () {
    $this->artisan('truss:export', ['--fresh' => true, '--output' => $this->tmp])
        ->assertExitCode(0);

    expect(file_get_contents($this->tmp))->toContain('Table posts {');
});

it('renders config annotations into the export by default', function () {
    config([
        'truss.annotations.source' => ['config'],
        'truss.annotations.notes' => ['All timestamps are UTC.'],
        'truss.annotations.tables' => ['users' => 'One row per account.'],
        'truss.annotations.columns' => ['users.name' => 'Display name.'],
    ]);

    $this->artisan('truss:export', ['--format' => 'markdown', '--output' => $this->tmp])
        ->assertExitCode(0);

    $out = (string) file_get_contents($this->tmp);
    expect($out)->toContain('> All timestamps are UTC.')
        ->and($out)->toContain('> One row per account.')
        ->and($out)->toContain('Display name.');
});

it('strips annotations with --no-annotations', function () {
    config([
        'truss.annotations.source' => ['config'],
        'truss.annotations.columns' => ['users.name' => 'Display name.'],
    ]);

    $this->artisan('truss:export', ['--format' => 'markdown', '--no-annotations' => true, '--output' => $this->tmp])
        ->assertExitCode(0);

    expect(file_get_contents($this->tmp))->not->toContain('Display name.')
        ->not->toContain('Description');
});

it('shrinks the output with --compact and keeps every table', function () {
    Schema::create('accounts', function ($table) {
        $table->id();
        $table->string('status')->default('active');
        $table->string('email')->unique();
        $table->index('status');
    });

    $this->artisan('truss:export', ['--output' => $this->tmp])->assertExitCode(0);
    $full = (string) file_get_contents($this->tmp);

    $this->artisan('truss:export', ['--compact' => true, '--output' => $this->tmp])->assertExitCode(0);
    $compact = (string) file_get_contents($this->tmp);

    expect(strlen($compact))->toBeLessThan(strlen($full))
        ->and($compact)->not->toContain("default: 'active'")
        ->and($compact)->toContain('Table accounts {')
        // Unique survives (in the indexes block); the non-unique status index is
        // gone. The native type token varies by driver, so match it loosely.
        ->and($compact)->toContain('email [unique]')
        ->and($compact)->toMatch('/\n  status .+\[not null\]/');
});

it('reduces the export to a table and its neighbourhood with --focus', function () {
    Schema::create('teams', fn ($table) => $table->id());
    Schema::create('projects', function ($table) {
        $table->id();
        $table->foreignId('team_id')->constrained();
    });

    // posts/users (from beforeEach) plus teams/projects; focus on projects at
    // depth 1 keeps projects + teams and drops the unrelated posts/users.
    $this->artisan('truss:export', ['--focus' => 'projects', '--depth' => '1', '--output' => $this->tmp])
        ->assertExitCode(0);

    $out = (string) file_get_contents($this->tmp);
    expect($out)->toContain('Table projects {')
        ->and($out)->toContain('Table teams {')
        ->and($out)->not->toContain('Table posts {')
        ->and($out)->not->toContain('Table users {');
});

it('exits 2 when --focus names a table that is not in the set', function () {
    $this->artisan('truss:export', ['--focus' => 'ghost'])
        ->expectsOutputToContain('Cannot focus on [ghost]')
        ->assertExitCode(2);
});

it('uses truss.export.default_format when --format is omitted', function () {
    config(['truss.export.default_format' => 'json']);

    $code = Artisan::call('truss:export');

    expect($code)->toBe(0)
        ->and(Artisan::output())->toStartWith('[');
});

it('never leaks row data: the canary never appears in any format', function () {
    Schema::create('secrets', function ($table) {
        $table->id();
        $table->string('token');
    });
    DB::table('secrets')->insert(['token' => 'TRUSS_ROW_DATA_CANARY']);

    // Annotations on too, so the canary sweep also covers the annotated paths.
    config([
        'truss.annotations.source' => ['config'],
        'truss.annotations.notes' => ['A note.'],
        'truss.annotations.columns' => ['users.name' => 'The name.'],
    ]);

    foreach (SchemaExporter::formats() as $format) {
        $out = $this->tmp.".{$format}";
        $this->artisan('truss:export', ['--format' => $format, '--output' => $out])->assertExitCode(0);

        expect(file_get_contents($out))->not->toContain('TRUSS_ROW_DATA_CANARY');
        unlink($out);
    }
});
