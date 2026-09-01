<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Rules\Integrity\ForeignKeyPointsAtWrongTable;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('flags a foreign key that points at a different table from the one its name names', function () {
    // The case this rule was written for, found in Lunar 1.5.0 on 01/09/2026:
    // cart_line_discount.cart_line_id is constrained against carts, not
    // cart_lines, so the cascade fires on the wrong parent and an insert only
    // succeeds when the cart line id happens to also exist as a cart id.
    $snapshot = SchemaBuilder::make()
        ->table('carts', fn ($t) => $t->id())
        ->table('cart_lines', fn ($t) => $t->id())
        ->table('cart_line_discount', fn ($t) => $t->id()->foreignId('cart_line_id')->on('carts'))
        ->build();

    expect(doctorCheck(new ForeignKeyPointsAtWrongTable, $snapshot))
        ->toHaveFinding('TRUSS-INT-010', table: 'cart_line_discount', column: 'cart_line_id');
});

it('is clean when the foreign key points at the table its name names', function () {
    $snapshot = SchemaBuilder::make()
        ->table('carts', fn ($t) => $t->id())
        ->table('cart_lines', fn ($t) => $t->id())
        ->table('cart_line_discount', fn ($t) => $t->id()->foreignId('cart_line_id')->on('cart_lines'))
        ->build();

    expect(doctorCheck(new ForeignKeyPointsAtWrongTable, $snapshot))->toBeClean();
});

it('matches a singular table name as well as a plural one', function () {
    $snapshot = SchemaBuilder::make()
        ->table('cart', fn ($t) => $t->id())
        ->table('cart_line', fn ($t) => $t->id())
        ->table('cart_line_discount', fn ($t) => $t->id()->foreignId('cart_line_id')->on('cart'))
        ->build();

    expect(doctorCheck(new ForeignKeyPointsAtWrongTable, $snapshot))
        ->toHaveFinding('TRUSS-INT-010', table: 'cart_line_discount', column: 'cart_line_id');
});

// The false positives this rule exists to avoid. Six of the seven column/table
// name mismatches in Lunar's 83 foreign keys are deliberate aliases, so a rule
// that flags every mismatch would be 86% wrong. All six shapes are below and
// none of them may fire.

it('does not flag an alias whose named table does not exist', function () {
    // merged_id -> carts, parent_transaction_id -> transactions,
    // product_parent_id -> products. There is no mergeds, parent_transactions
    // or product_parents table, so the name names nothing and there is no
    // disagreement to report.
    $snapshot = SchemaBuilder::make()
        ->table('carts', fn ($t) => $t->id()->foreignId('merged_id')->on('carts'))
        ->table('transactions', fn ($t) => $t->id()->foreignId('parent_transaction_id')->on('transactions'))
        ->table('products', fn ($t) => $t->id())
        ->table('product_associations', fn ($t) => $t->id()
            ->foreignId('product_parent_id')->on('products')
            ->foreignId('product_target_id')->on('products'))
        ->build();

    expect(doctorCheck(new ForeignKeyPointsAtWrongTable, $snapshot))->toBeClean();
});

it('does not flag a shortened column name that already points at the right table', function () {
    // value_id -> product_option_values and variant_id -> product_variants.
    // The referenced table ends with the plural of the column base, so the
    // shortened name is an abbreviation of the target rather than a different
    // table.
    $snapshot = SchemaBuilder::make()
        ->table('product_option_values', fn ($t) => $t->id())
        ->table('product_variants', fn ($t) => $t->id())
        ->table('product_option_value_product_variant', fn ($t) => $t->id()
            ->foreignId('value_id')->on('product_option_values')
            ->foreignId('variant_id')->on('product_variants'))
        ->build();

    expect(doctorCheck(new ForeignKeyPointsAtWrongTable, $snapshot))->toBeClean();
});

it('finds the table through a shared prefix', function () {
    // Lunar prefixes every table with lunar_. The candidate is found by taking
    // the prefix off the table the key already references, so a prefixed schema
    // is not invisible to this rule.
    $snapshot = SchemaBuilder::make()
        ->table('lunar_carts', fn ($t) => $t->id())
        ->table('lunar_cart_lines', fn ($t) => $t->id())
        ->table('lunar_cart_line_discount', fn ($t) => $t->id()->foreignId('cart_line_id')->on('lunar_carts'))
        ->build();

    expect(doctorCheck(new ForeignKeyPointsAtWrongTable, $snapshot))
        ->toHaveFinding('TRUSS-INT-010', table: 'lunar_cart_line_discount', column: 'cart_line_id');
});

it('does not match across different prefixes', function () {
    // A cart_lines table exists, but under a different prefix from the one the
    // key references, so it is a different application's table and not evidence
    // that this key is wrong.
    $snapshot = SchemaBuilder::make()
        ->table('lunar_carts', fn ($t) => $t->id())
        ->table('shop_cart_lines', fn ($t) => $t->id())
        ->table('lunar_cart_line_discount', fn ($t) => $t->id()->foreignId('cart_line_id')->on('lunar_carts'))
        ->build();

    expect(doctorCheck(new ForeignKeyPointsAtWrongTable, $snapshot))->toBeClean();
});

it('skips when more than one table could be the intended target', function () {
    // "lines" exists unprefixed, and "shop_lines" exists under the same prefix
    // as the referenced table. Both are plausible and choosing either one would
    // be a guess, so the rule says nothing.
    $snapshot = SchemaBuilder::make()
        ->table('lines', fn ($t) => $t->id())
        ->table('shop_lines', fn ($t) => $t->id())
        ->table('shop_carts', fn ($t) => $t->id())
        ->table('shop_line_discount', fn ($t) => $t->id()->foreignId('line_id')->on('shop_carts'))
        ->build();

    expect(doctorCheck(new ForeignKeyPointsAtWrongTable, $snapshot))->toBeClean();
});

it('skips a morph target, which cannot name one table', function () {
    // A *_id with a sibling *_type points at whichever table the type column
    // names, so its name is not a claim about a single table. TRUSS-INT-009
    // owns this shape.
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('owners', fn ($t) => $t->id())
        ->table('things', fn ($t) => $t->id()->string('owner_type')->foreignId('owner_id')->on('users'))
        ->build();

    expect(doctorCheck(new ForeignKeyPointsAtWrongTable, $snapshot))->toBeClean();
});

it('skips a composite foreign key', function () {
    // A multi-column key is not named after one table, so the name test does
    // not apply.
    $snapshot = SchemaBuilder::make()
        ->table('carts', fn ($t) => $t->id())
        ->table('cart_lines', fn ($t) => $t->id())
        ->table('cart_line_discount', fn ($t) => $t->id()
            ->integer('cart_line_id')
            ->integer('tenant_id')
            ->compositeForeign(['cart_line_id', 'tenant_id'], 'carts'))
        ->build();

    expect(doctorCheck(new ForeignKeyPointsAtWrongTable, $snapshot))->toBeClean();
});

it('ignores a column that is not foreign-key shaped', function () {
    $snapshot = SchemaBuilder::make()
        ->table('carts', fn ($t) => $t->id())
        ->table('cart_lines', fn ($t) => $t->id())
        ->table('cart_line_discount', fn ($t) => $t->id()->integer('quantity')->foreign('quantity', 'carts'))
        ->build();

    expect(doctorCheck(new ForeignKeyPointsAtWrongTable, $snapshot))->toBeClean();
});

it('is clean on a schema with no foreign keys at all', function () {
    $snapshot = SchemaBuilder::make()
        ->table('carts', fn ($t) => $t->id())
        ->table('cart_lines', fn ($t) => $t->id())
        ->build();

    expect(doctorCheck(new ForeignKeyPointsAtWrongTable, $snapshot))->toBeClean();
});

it('names both tables in the message, because the fix is unreadable without them', function () {
    $snapshot = SchemaBuilder::make()
        ->table('carts', fn ($t) => $t->id())
        ->table('cart_lines', fn ($t) => $t->id())
        ->table('cart_line_discount', fn ($t) => $t->id()->foreignId('cart_line_id')->on('carts'))
        ->build();

    $findings = doctorCheck(new ForeignKeyPointsAtWrongTable, $snapshot);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('carts')
        ->and($findings[0]->message)->toContain('cart_lines');
});
