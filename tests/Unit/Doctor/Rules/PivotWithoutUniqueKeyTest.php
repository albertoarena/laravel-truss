<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Rules\Integrity\PivotWithoutUniqueKey;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('flags a two-foreign-key table with no unique constraint on the pair', function () {
    // An id primary key does not stop duplicate (team_id, user_id) rows.
    $snapshot = SchemaBuilder::make()
        ->table('teams', fn ($t) => $t->id())
        ->table('users', fn ($t) => $t->id())
        ->table('team_user', fn ($t) => $t->id()
            ->foreignId('team_id')->on('teams')
            ->foreignId('user_id')->on('users'))
        ->build();

    expect(doctorCheck(new PivotWithoutUniqueKey, $snapshot))
        ->toHaveFinding('TRUSS-INT-007', table: 'team_user');
});

it('is clean when the key pair is the composite primary key', function () {
    $snapshot = SchemaBuilder::make()
        ->table('teams', fn ($t) => $t->id())
        ->table('users', fn ($t) => $t->id())
        ->table('team_user', fn ($t) => $t
            ->foreignId('team_id')->on('teams')
            ->foreignId('user_id')->on('users')
            ->primary(['team_id', 'user_id']))
        ->build();

    expect(doctorCheck(new PivotWithoutUniqueKey, $snapshot))->toBeClean();
});

it('is clean when a unique index covers the key pair', function () {
    $snapshot = SchemaBuilder::make()
        ->table('teams', fn ($t) => $t->id())
        ->table('users', fn ($t) => $t->id())
        ->table('team_user', fn ($t) => $t->id()
            ->foreignId('team_id')->on('teams')
            ->foreignId('user_id')->on('users')
            ->unique(['team_id', 'user_id']))
        ->build();

    expect(doctorCheck(new PivotWithoutUniqueKey, $snapshot))->toBeClean();
});

it('ignores tables that are not two-foreign-key pivots', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('posts', fn ($t) => $t->id()->foreignId('user_id')->on('users'))
        ->build();

    expect(doctorCheck(new PivotWithoutUniqueKey, $snapshot))->toBeClean();
});

/*
 * Narrowing from ADR 0003. The field study found this rule wrong 56 times out of
 * 69 because "two single-column foreign keys" was its whole definition of a
 * pivot. These cases are the shapes that actually broke it.
 */

it('does not call a wide entity table a pivot', function () {
    // bagisto.cart: 36 columns, its own key, two foreign keys. Making
    // (channel_id, customer_id) unique would cap a customer at one cart forever.
    $snapshot = SchemaBuilder::make()
        ->table('channels', fn ($t) => $t->id())
        ->table('customers', fn ($t) => $t->id())
        ->table('cart', fn ($t) => $t->id()
            ->foreignId('channel_id')->on('channels')
            ->foreignId('customer_id')->on('customers')
            ->string('coupon_code')
            ->integer('items_count')
            ->integer('items_qty')
            ->string('currency_code')
            ->integer('grand_total'))
        ->build();

    expect(doctorCheck(new PivotWithoutUniqueKey, $snapshot))->toBeClean();
});

it('does not call an entity with its own key and one payload column a pivot', function () {
    // koel.playlist_folders: an id, two foreign keys, and a name. A folder is a
    // thing, not a relationship, and its name is what proves it.
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('playlist_folders', fn ($t) => $t->id()
            ->foreignId('user_id')->on('users')
            ->foreignId('parent_id')->on('users')
            ->string('name'))
        ->build();

    expect(doctorCheck(new PivotWithoutUniqueKey, $snapshot))->toBeClean();
});

it('still flags a pivot that carries a surrogate key and nothing else', function () {
    // koel.genre_song: an id primary key does not stop duplicate pairs, so this
    // is a real finding and the narrowing must not lose it.
    $snapshot = SchemaBuilder::make()
        ->table('genres', fn ($t) => $t->id())
        ->table('songs', fn ($t) => $t->id())
        ->table('genre_song', fn ($t) => $t->id()
            ->foreignId('genre_id')->on('genres')
            ->foreignId('song_id')->on('songs'))
        ->build();

    expect(doctorCheck(new PivotWithoutUniqueKey, $snapshot))
        ->toHaveFinding('TRUSS-INT-007', table: 'genre_song');
});

it('does not count timestamps against a pivot', function () {
    $snapshot = SchemaBuilder::make()
        ->table('posts', fn ($t) => $t->id())
        ->table('tags', fn ($t) => $t->id())
        ->table('post_tag', fn ($t) => $t
            ->foreignId('post_id')->on('posts')
            ->foreignId('tag_id')->on('tags')
            ->column('created_at', 'timestamp', true)
            ->column('updated_at', 'timestamp', true))
        ->build();

    expect(doctorCheck(new PivotWithoutUniqueKey, $snapshot))
        ->toHaveFinding('TRUSS-INT-007', table: 'post_tag');
});

it('allows a key-less join table one payload column', function () {
    // monica.contact_address: no primary key at all, so it is unambiguously a
    // join table even though it carries a flag of its own.
    $snapshot = SchemaBuilder::make()
        ->table('contacts', fn ($t) => $t->id())
        ->table('addresses', fn ($t) => $t->id())
        ->table('contact_address', fn ($t) => $t
            ->foreignId('contact_id')->on('contacts')
            ->foreignId('address_id')->on('addresses')
            ->column('is_active', 'tinyint', true))
        ->build();

    expect(doctorCheck(new PivotWithoutUniqueKey, $snapshot))
        ->toHaveFinding('TRUSS-INT-007', table: 'contact_address');
});

/*
 * Issue #58. A one-to-one profile table with a unique user_id and an optional
 * belongs-to lookup, reported as a pivot on v1.9.1. Two faults on one table:
 * it is not a join table, and its pair cannot repeat anyway.
 */

it('does not call a one-to-one profile table a pivot', function () {
    // The schema exactly as reported in issue #58.
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('insurance_plans', fn ($t) => $t->id())
        ->table('patient_profiles', fn ($t) => $t->id()
            ->foreignId('user_id')->on('users')
            ->foreignId('insurance_plan_id')->on('insurance_plans')
            ->unique('user_id')
            ->string('full_name')
            ->string('gender')
            ->string('phone_number')
            ->column('address', 'text', true)
            ->string('emergency_contact_name')
            ->string('emergency_contact_phone')
            ->column('profile_completed_at', 'timestamp', true)
            ->index('profile_completed_at')
            ->column('created_at', 'timestamp', true)
            ->column('updated_at', 'timestamp', true))
        ->build();

    expect(doctorCheck(new PivotWithoutUniqueKey, $snapshot))->toBeClean();
});

it('is clean when a unique index covers only part of the key pair', function () {
    // The same one-to-one shape with no payload at all, so the column count
    // cannot save it. A unique user_id already makes (user_id, team_id) unique,
    // so "duplicate pairs are possible" would be false whatever else is decided.
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('teams', fn ($t) => $t->id())
        ->table('user_settings', fn ($t) => $t->id()
            ->foreignId('user_id')->on('users')
            ->foreignId('team_id')->on('teams')
            ->unique('user_id'))
        ->build();

    expect(doctorCheck(new PivotWithoutUniqueKey, $snapshot))->toBeClean();
});

it('still flags a pivot whose unique column is nullable', function () {
    // Most engines allow repeated NULLs in a unique index, so a nullable unique
    // column does not prove the pair cannot repeat.
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('teams', fn ($t) => $t->id())
        ->table('invites', fn ($t) => $t->id()
            ->column('user_id', 'bigint unsigned', nullable: true)
            ->column('team_id', 'bigint unsigned', nullable: true)
            ->foreign('user_id', 'users')
            ->foreign('team_id', 'teams')
            ->unique('user_id'))
        ->build();

    expect(doctorCheck(new PivotWithoutUniqueKey, $snapshot))
        ->toHaveFinding('TRUSS-INT-007', table: 'invites');
});
