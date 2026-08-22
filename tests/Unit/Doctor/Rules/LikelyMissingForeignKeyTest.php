<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Rules\Integrity\LikelyMissingForeignKey;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('flags a *_id column that matches a table but has no foreign key', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('posts', fn ($t) => $t->id()->foreignId('user_id')) // column, no ->on()
        ->build();

    expect(doctorCheck(new LikelyMissingForeignKey, $snapshot))
        ->toHaveFinding('TRUSS-INT-002', table: 'posts', column: 'user_id');
});

it('is clean when the column already has a foreign key', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('posts', fn ($t) => $t->id()->foreignId('user_id')->on('users'))
        ->build();

    expect(doctorCheck(new LikelyMissingForeignKey, $snapshot))->toBeClean();
});

it('does not flag a *_id column with no matching table, such as a cross-database reference', function () {
    // external_user_id points at a user in another database; there is no local
    // "external_users" table, so there is nothing to constrain to.
    $snapshot = SchemaBuilder::make()
        ->table('subscriptions', fn ($t) => $t->id()->foreignId('external_user_id'))
        ->build();

    expect(doctorCheck(new LikelyMissingForeignKey, $snapshot))->toBeClean();
});

it('matches a singular table name too', function () {
    $snapshot = SchemaBuilder::make()
        ->table('user', fn ($t) => $t->id())
        ->table('posts', fn ($t) => $t->id()->foreignId('user_id'))
        ->build();

    expect(doctorCheck(new LikelyMissingForeignKey, $snapshot))
        ->toHaveFinding('TRUSS-INT-002', table: 'posts', column: 'user_id');
});

it('is heuristic, so it stays off the recommended default', function () {
    expect((new LikelyMissingForeignKey)->confidence()->value)->toBe('heuristic');
});

/*
 * From the field study: nine of this rule's forty-five findings sat on
 * polymorphic columns, where a foreign key is not merely absent but impossible.
 * Truss contradicted itself in a single report, since TRUSS-INT-009 identified
 * the same columns as polymorphic.
 */

it('does not ask for a foreign key on a polymorphic column', function () {
    // cachet.taggables: taggable_id can point at any taggable model, so no
    // single foreign key can exist. The sibling taggable_type column says so.
    $snapshot = SchemaBuilder::make()
        ->table('taggables', fn ($t) => $t->morphs('taggable'))
        ->table('tags', fn ($t) => $t->id())
        ->build();

    expect(doctorCheck(new LikelyMissingForeignKey, $snapshot))->toBeClean();
});

it('still flags a plain column even when the table has an unrelated morph', function () {
    // The exemption is per column pair, not per table: user_id here is a real
    // reference and keeps its finding.
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('activities', fn ($t) => $t->id()
            ->morphs('subject')
            ->foreignId('user_id'))
        ->build();

    expect(doctorCheck(new LikelyMissingForeignKey, $snapshot))
        ->toHaveFinding('TRUSS-INT-002', table: 'activities', column: 'user_id');
});
