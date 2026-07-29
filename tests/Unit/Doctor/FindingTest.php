<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;

function doctorFinding(
    string $code = 'TRUSS-IDX-001',
    string $table = 'posts',
    ?string $column = 'user_id',
    string $message = 'a message',
    string $connection = 'mysql',
): Finding {
    return new Finding(
        code: $code,
        severity: Severity::Error,
        connection: $connection,
        table: $table,
        column: $column,
        message: $message,
        hint: 'why it matters',
    );
}

it('fingerprints on code, connection, table and column, not the message', function () {
    // A reworded message must not change the fingerprint, or a suppression breaks.
    expect(doctorFinding(message: 'wording one')->fingerprint())
        ->toBe(doctorFinding(message: 'totally different wording')->fingerprint());
});

it('changes the fingerprint when the column changes', function () {
    expect(doctorFinding(column: 'user_id')->fingerprint())
        ->not->toBe(doctorFinding(column: 'author_id')->fingerprint());
});

it('distinguishes a column-less finding from a column one', function () {
    expect(doctorFinding(column: null)->fingerprint())
        ->not->toBe(doctorFinding(column: 'user_id')->fingerprint());
});

it('changes the fingerprint when the connection or code changes', function () {
    expect(doctorFinding(connection: 'mysql')->fingerprint())
        ->not->toBe(doctorFinding(connection: 'tenant')->fingerprint());
    expect(doctorFinding(code: 'TRUSS-IDX-001')->fingerprint())
        ->not->toBe(doctorFinding(code: 'TRUSS-IDX-002')->fingerprint());
});

it('is stable across calls', function () {
    $f = doctorFinding();
    expect($f->fingerprint())->toBe($f->fingerprint());
});
