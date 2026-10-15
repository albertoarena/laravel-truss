<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Cli;

/**
 * A connection string, turned into the connection array the bootstrapper hands
 * to the Capsule.
 *
 * Pure: nothing here opens a connection, reads a file or touches the
 * container. A DSN is a string until something connects with it, and keeping
 * the two apart is what lets every awkward form be tested without a database.
 *
 * **Nothing in this class puts the connection string into a message.** A DSN
 * carries a password and an error message travels further than the terminal it
 * appeared in, so failures describe what was expected or name the driver and
 * host, never what was typed. `describe()` is the redacted form everything
 * else should print.
 */
final class Dsn
{
    /**
     * The schemes accepted, mapped to Laravel's driver names.
     *
     * `postgres` and `postgresql` are here because they are what people type
     * and what every other tool accepts. `mariadb` is deliberately not folded
     * into `mysql`: Laravel has had a separate driver since 11 and its schema
     * grammar differs, so collapsing them would make the binary report a
     * different structure than the artisan command does about one database.
     *
     * @var array<string, string>
     */
    private const DRIVERS = [
        'mysql' => 'mysql',
        'mariadb' => 'mariadb',
        'pgsql' => 'pgsql',
        'postgres' => 'pgsql',
        'postgresql' => 'pgsql',
        'sqlite' => 'sqlite',
    ];

    /** @var array<string, int> */
    private const PORTS = ['mysql' => 3306, 'mariadb' => 3306, 'pgsql' => 5432];

    /** Endings that make a bare path unambiguously a SQLite file. */
    private const SQLITE_EXTENSIONS = ['.sqlite', '.sqlite3', '.db'];

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidDsn
     */
    public static function parse(string $dsn): array
    {
        $dsn = trim($dsn);

        if ($dsn === '') {
            throw new InvalidDsn('No connection string was given. Pass one with TRUSS_DSN or --dsn.');
        }

        if (str_starts_with($dsn, 'sqlite:')) {
            return self::sqlite(self::stripSqliteScheme($dsn));
        }

        // No scheme at all is a SQLite file, which is the shortest useful thing
        // somebody can type. Only when it looks like a path, though: a bare
        // word is far more likely to be a typo than a filename, and silently
        // treating it as one produces "unable to open database file" later,
        // which sends the reader to look at permissions.
        if (! str_contains($dsn, '://')) {
            if (self::looksLikeSqliteFile($dsn)) {
                return self::sqlite($dsn);
            }

            throw new InvalidDsn(self::expectation());
        }

        $parts = parse_url($dsn);

        if ($parts === false || ! isset($parts['scheme'])) {
            throw new InvalidDsn(self::expectation());
        }

        $scheme = strtolower($parts['scheme']);

        if (! isset(self::DRIVERS[$scheme])) {
            throw new InvalidDsn("Truss cannot read a schema from [{$scheme}]. ".self::expectation());
        }

        $driver = self::DRIVERS[$scheme];

        if ($driver === 'sqlite') {
            return self::sqlite(self::stripSqliteScheme($dsn));
        }

        return self::server($driver, $parts);
    }

    /**
     * The connection as a line safe to print: driver and where, never who.
     *
     * This is what an unreachable connection reports, so somebody reading a CI
     * log can tell which database was meant without the log carrying the
     * credentials for it.
     *
     * @param  array<string, mixed>  $connection
     */
    public static function describe(array $connection): string
    {
        $driver = (string) ($connection['driver'] ?? 'unknown');

        if ($driver === 'sqlite') {
            return $driver.' at '.(string) ($connection['database'] ?? '');
        }

        return sprintf(
            '%s at %s:%s/%s',
            $driver,
            (string) ($connection['host'] ?? ''),
            (string) ($connection['port'] ?? ''),
            (string) ($connection['database'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function sqlite(string $database): array
    {
        if ($database === '') {
            throw new InvalidDsn('The connection string names no database file. '.self::expectation());
        }

        return [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $parts
     * @return array<string, mixed>
     */
    private static function server(string $driver, array $parts): array
    {
        $host = isset($parts['host']) ? (string) $parts['host'] : '';
        $port = isset($parts['port']) ? (int) $parts['port'] : self::PORTS[$driver];

        if ($host === '') {
            throw new InvalidDsn("The connection string names no host for [{$driver}]. ".self::expectation());
        }

        $database = rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/'));

        if ($database === '') {
            throw new InvalidDsn("The connection string names no database: {$driver} at {$host}:{$port}.");
        }

        $connection = [
            ...self::defaults($driver),
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => rawurldecode((string) ($parts['user'] ?? '')),
            'password' => rawurldecode((string) ($parts['pass'] ?? '')),
        ];

        // Query parameters last, so `?charset=latin1` or `?sslmode=require`
        // override a default rather than being quietly dropped. Unknown keys
        // are passed through: the connectors ignore what they do not use, and
        // the alternative is an allow-list that goes stale every time
        // Illuminate adds an option.
        return [...$connection, ...self::query($parts['query'] ?? null)];
    }

    /**
     * Laravel's own defaults for the driver, so a schema read through the
     * binary matches one read through artisan in a standard application.
     *
     * @return array<string, mixed>
     */
    private static function defaults(string $driver): array
    {
        $common = ['prefix' => '', 'prefix_indexes' => true];

        return match ($driver) {
            'pgsql' => [...$common, 'charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'prefer'],
            default => [...$common, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true, 'engine' => null],
        };
    }

    /**
     * @return array<string, string>
     */
    private static function query(?string $query): array
    {
        if ($query === null || $query === '') {
            return [];
        }

        $parsed = [];
        parse_str($query, $parsed);

        return array_map(static fn (mixed $value): string => is_array($value) ? (string) end($value) : (string) $value, $parsed);
    }

    /**
     * `sqlite:///var/db/app.sqlite`, `sqlite:app.sqlite` and `sqlite::memory:`
     * are all in use, and `parse_url` reads the three differently, so the
     * scheme comes off by hand.
     */
    private static function stripSqliteScheme(string $dsn): string
    {
        $rest = substr($dsn, strlen('sqlite:'));

        return str_starts_with($rest, '//') ? substr($rest, 2) : $rest;
    }

    private static function looksLikeSqliteFile(string $dsn): bool
    {
        if ($dsn === ':memory:' || str_contains($dsn, '/')) {
            return true;
        }

        foreach (self::SQLITE_EXTENSIONS as $extension) {
            if (str_ends_with(strtolower($dsn), $extension)) {
                return true;
            }
        }

        return false;
    }

    private static function expectation(): string
    {
        return 'A connection string could not be understood. Expected mysql://, mariadb://, pgsql:// or sqlite:, or the path to a SQLite file.';
    }
}
