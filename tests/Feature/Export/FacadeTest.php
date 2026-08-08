<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\ExportBuilder;
use AlbertoArena\Truss\Facades\Truss;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('email')->unique();
    });
    Schema::create('posts', function ($table) {
        $table->id();
        $table->foreignId('user_id')->constrained();
        $table->string('status')->default('draft');
    });
    Schema::create('audit', fn ($table) => $table->id());
});

it('returns a fresh export builder from snapshot()', function () {
    expect(Truss::snapshot())->toBeInstanceOf(ExportBuilder::class);
});

it('renders each terminal format', function () {
    expect(Truss::snapshot()->toDbml())->toContain('Table posts {')
        ->and(Truss::snapshot()->toMarkdown())->toContain('# Data dictionary')
        ->and(Truss::snapshot()->toMermaid())->toContain('erDiagram')
        ->and(Truss::snapshot()->toCsv())->toStartWith('table,name,type')
        ->and(Truss::snapshot()->toLlm())->toContain('posts')
        ->and(json_decode(Truss::snapshot()->toJson(), true))->toBeArray()
        ->and(collect(Truss::snapshot()->toArray())->pluck('name')->all())->toBe(['audit', 'posts', 'users']);
});

it('applies only(), except(), and focus() filters', function () {
    expect(collect(Truss::snapshot()->only(['users'])->toArray())->pluck('name')->all())->toBe(['users'])
        ->and(collect(Truss::snapshot()->except(['audit'])->toArray())->pluck('name')->all())->toBe(['posts', 'users'])
        ->and(collect(Truss::snapshot()->focus('posts', depth: 1)->toArray())->pluck('name')->all())->toBe(['posts', 'users']);
});

it('drops defaults with compact() and strips annotations with withoutAnnotations()', function () {
    config(['truss.annotations.source' => ['config'], 'truss.annotations.columns' => ['users.email' => 'the login']]);

    expect(Truss::snapshot()->compact()->toDbml())->not->toContain('default:')
        ->and(Truss::snapshot()->toDbml())->toContain('default:')
        ->and(Truss::snapshot()->toDbml())->toContain("note: 'the login'")
        ->and(Truss::snapshot()->withoutAnnotations()->toDbml())->not->toContain('the login');
});

it('is immutable: a modifier returns a new instance and never mutates the base', function () {
    $base = Truss::snapshot();
    $compact = $base->compact();

    expect($compact)->not->toBe($base)
        // The base still renders the full output; the derived builder is compact.
        ->and($base->toDbml())->toContain('default:')
        ->and($compact->toDbml())->not->toContain('default:');
});

it('rejects an unmanaged connection', function () {
    Truss::snapshot()->connection('nope')->toArray();
})->throws(InvalidArgumentException::class, 'not managed by Truss');

it('raises on a missing focus table', function () {
    Truss::snapshot()->focus('ghost')->toArray();
})->throws(InvalidArgumentException::class, 'Cannot focus on [ghost]');

it('produces output byte-identical to the command for the same filters', function () {
    foreach (['dbml', 'json', 'csv', 'markdown', 'mermaid', 'llm'] as $format) {
        Artisan::call('truss:export', ['--format' => $format, '--compact' => true, '--focus' => 'posts', '--depth' => '1']);
        $fromCommand = Artisan::output();

        $fromFacade = Truss::snapshot()->focus('posts', depth: 1)->compact()->render($format);

        expect($fromFacade)->toBe($fromCommand, "format {$format}");
    }
});
