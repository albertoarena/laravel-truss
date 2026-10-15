<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cli\Dsn;
use AlbertoArena\Truss\Cli\InvalidDsn;

/*
 * Pure, and no database is opened anywhere in this file: a DSN is a string
 * until something connects with it, and the parser's job is to turn it into
 * the connection array the bootstrapper hands to the Capsule.
 *
 * The cases here are the ones that bite in practice rather than a tour of the
 * grammar: an encoded password, a non-default port, a missing database name,
 * query parameters that matter (sslmode, search_path), and a string that is
 * not a DSN at all producing a sentence rather than a stack trace.
 */

it('parses a full mysql dsn', function (): void {
    $connection = Dsn::parse('mysql://shop_ro:secret@db.internal:3307/shop');

    expect($connection)->toMatchArray([
        'driver' => 'mysql',
        'host' => 'db.internal',
        'port' => 3307,
        'database' => 'shop',
        'username' => 'shop_ro',
        'password' => 'secret',
        'prefix' => '',
    ]);
});

it('gives the port as an integer, because the connectors compare it as one', function (): void {
    expect(Dsn::parse('mysql://localhost:3307/shop')['port'])->toBeInt();
});

it('falls back to the driver default port', function (string $dsn, int $port): void {
    expect(Dsn::parse($dsn)['port'])->toBe($port);
})->with([
    ['mysql://localhost/shop', 3306],
    ['mariadb://localhost/shop', 3306],
    ['pgsql://localhost/shop', 5432],
]);

it('accepts the names people actually type for postgres', function (string $scheme): void {
    expect(Dsn::parse($scheme.'://localhost/shop')['driver'])->toBe('pgsql');
})->with(['pgsql', 'postgres', 'postgresql']);

it('keeps mariadb as its own driver rather than folding it into mysql', function (): void {
    // Laravel has had a mariadb driver since 11, and its schema grammar
    // differs, so folding it into mysql would report a different structure
    // than the artisan command does against the same database.
    expect(Dsn::parse('mariadb://localhost/shop')['driver'])->toBe('mariadb');
});

it('decodes a percent-encoded password', function (): void {
    // A password with @ or / in it cannot be written literally in a URL, so
    // this is not an edge case, it is what a generated password looks like.
    expect(Dsn::parse('mysql://root:p%40ss%2Fw%3Ard@127.0.0.1/shop')['password'])->toBe('p@ss/w:rd');
});

it('decodes a percent-encoded username and database name', function (): void {
    $connection = Dsn::parse('pgsql://read%2Donly:x@host/my%20db');

    expect($connection['username'])->toBe('read-only')
        ->and($connection['database'])->toBe('my db');
});

it('carries query parameters through, which is how sslmode and search_path arrive', function (): void {
    $connection = Dsn::parse('pgsql://u:p@host/shop?sslmode=require&search_path=app,public');

    expect($connection['sslmode'])->toBe('require')
        ->and($connection['search_path'])->toBe('app,public');
});

it('lets a query parameter override a default rather than being ignored', function (): void {
    expect(Dsn::parse('mysql://localhost/shop?charset=latin1')['charset'])->toBe('latin1');
});

it('parses the sqlite forms', function (string $dsn, string $database): void {
    $connection = Dsn::parse($dsn);

    expect($connection['driver'])->toBe('sqlite')
        ->and($connection['database'])->toBe($database);
})->with([
    'absolute, three slashes' => ['sqlite:///var/db/app.sqlite', '/var/db/app.sqlite'],
    'relative' => ['sqlite:app.sqlite', 'app.sqlite'],
    'in memory' => ['sqlite::memory:', ':memory:'],
    'bare absolute path' => ['/var/db/app.sqlite', '/var/db/app.sqlite'],
    'bare relative path' => ['./app.sqlite', './app.sqlite'],
    'bare filename with a sqlite extension' => ['app.sqlite3', 'app.sqlite3'],
]);

it('refuses a bare word that is not a path, rather than treating it as a file', function (): void {
    // Without this, a typo becomes a SQLite file nobody asked for and the
    // error arrives later as "unable to open database file", which sends the
    // reader looking at permissions instead of at what they typed.
    expect(fn (): array => Dsn::parse('shop'))
        ->toThrow(InvalidDsn::class, 'could not be understood');
});

it('says what is missing when there is no database name', function (): void {
    expect(fn (): array => Dsn::parse('mysql://shop_ro:secret@db.internal:3307'))
        ->toThrow(InvalidDsn::class, 'names no database');
});

it('never puts the password in the error, because these end up in logs and tickets', function (): void {
    // The one rule that matters more than the wording: a DSN on a command line
    // is already in shell history, and an error message travels further.
    try {
        Dsn::parse('mysql://shop_ro:hunter2@db.internal:3307');
    } catch (InvalidDsn $e) {
        expect($e->getMessage())->not->toContain('hunter2')
            ->and($e->getMessage())->toContain('db.internal')
            ->and($e->getMessage())->toContain('mysql');

        return;
    }

    $this->fail('Expected an InvalidDsn.');
});

it('refuses a driver it cannot read a schema from', function (): void {
    expect(fn (): array => Dsn::parse('mongodb://localhost/shop'))
        ->toThrow(InvalidDsn::class, 'mongodb');
});

it('refuses an empty string with a sentence', function (string $dsn): void {
    expect(fn (): array => Dsn::parse($dsn))->toThrow(InvalidDsn::class);
})->with(['', '   ', 'mysql://', '://nowhere']);

it('describes a connection without its credentials', function (): void {
    // What an unreachable-connection message prints. It names the driver and
    // the host, per the plan's safety rule, and nothing else.
    $description = Dsn::describe(Dsn::parse('mysql://shop_ro:hunter2@db.internal:3307/shop'));

    expect($description)->toBe('mysql at db.internal:3307/shop')
        ->and($description)->not->toContain('hunter2')
        ->and($description)->not->toContain('shop_ro');
});

it('describes a sqlite connection by its file', function (): void {
    expect(Dsn::describe(Dsn::parse('sqlite:///var/db/app.sqlite')))->toBe('sqlite at /var/db/app.sqlite');
});
