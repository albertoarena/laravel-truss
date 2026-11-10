<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cli\ConnectionFailure;

/*
 * Pure: no database is opened here. Each case is the exception a real driver
 * raises, copied from what PDO prints, so the classification can be pinned on
 * every driver whether or not that server is running locally. The live lanes
 * prove the same thing against real servers in ServerRefusalTest.php.
 *
 * The questions are narrow on purpose: did the server answer and refuse the
 * credentials, or answer and say the database is not there? Everything else,
 * including errors nobody has seen yet, stays "could not reach", which is the
 * message the binary has always printed.
 */

it('recognises a refused password on MySQL and MariaDB', function (): void {
    $e = new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost' (using password: YES)", 1045);

    expect(ConnectionFailure::refusedCredentials($e))->toBeTrue();
});

it('recognises a refused password on MySQL by its message alone', function (): void {
    // The code is the reliable half, but a wrapper can lose it, so the
    // bracketed driver code in the message counts as well.
    $e = new RuntimeException("SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost' (using password: YES)");

    expect(ConnectionFailure::refusedCredentials($e))->toBeTrue();
});

it('recognises a refused password on PostgreSQL', function (): void {
    // libpq reports every failed connection as 08006, so the SQLSTATE cannot
    // tell a wrong password from a dead host. The server's own sentence can.
    $e = new PDOException('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: FATAL:  password authentication failed for user "truss"');

    expect(ConnectionFailure::refusedCredentials($e))->toBeTrue();
});

it('recognises a refused password on PostgreSQL by its SQLSTATE when the server sends one', function (): void {
    $e = new RuntimeException('SQLSTATE[28P01]: Invalid password: 7 FATAL: password authentication failed');

    expect(ConnectionFailure::refusedCredentials($e))->toBeTrue();
});

it('recognises an unknown user on a PostgreSQL server that does not ask for a password', function (): void {
    // A server on trust or peer authentication names the missing role. One
    // that asks for a password says "password authentication failed" instead,
    // so as not to confirm which users exist.
    $e = new PDOException('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: FATAL:  role "truss" does not exist');

    expect(ConnectionFailure::refusedCredentials($e))->toBeTrue()
        ->and(ConnectionFailure::unknownDatabase($e))->toBeFalse();
});

it('looks through a wrapping exception to the driver error underneath', function (): void {
    $driver = new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'shop'@'10.0.0.4' (using password: YES)", 1045);

    expect(ConnectionFailure::refusedCredentials(new RuntimeException('Could not connect', 0, $driver)))->toBeTrue();
});

it('leaves a network failure as a network failure', function (Throwable $e): void {
    expect(ConnectionFailure::refusedCredentials($e))->toBeFalse();
})->with([
    'mysql refused' => [new PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002)],
    'mysql unknown host' => [new PDOException('SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for nowhere failed', 2002)],
    'pgsql refused' => [new PDOException('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 1 failed: Connection refused')],
    'pgsql timeout' => [new PDOException('SQLSTATE[08006] [7] connection to server at "10.0.0.9", port 5432 failed: timeout expired')],
    'anything else' => [new RuntimeException('Something nobody has seen before')],
]);

it('does not take a code of 1045 from an exception that is not a driver error', function (): void {
    // 1045 means "access denied" only to MySQL. Elsewhere it is just a number.
    expect(ConnectionFailure::refusedCredentials(new RuntimeException('Disk full', 1045)))->toBeFalse();
});

it('recognises a database the server does not have', function (Throwable $e): void {
    expect(ConnectionFailure::unknownDatabase($e))->toBeTrue()
        ->and(ConnectionFailure::refusedCredentials($e))->toBeFalse();
})->with([
    'mysql and mariadb' => [new PDOException("SQLSTATE[HY000] [1049] Unknown database 'shop'", 1049)],
    'mysql by its message alone' => [new RuntimeException("SQLSTATE[HY000] [1049] Unknown database 'shop'")],
    'pgsql' => [new PDOException('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: FATAL:  database "shop" does not exist')],
    'pgsql by its SQLSTATE' => [new RuntimeException('SQLSTATE[3D000]: Invalid catalog name: 7 FATAL: database "shop" does not exist')],
    'wrapped' => [new RuntimeException('Could not connect', 0, new PDOException("SQLSTATE[HY000] [1049] Unknown database 'shop'", 1049))],
]);

it('leaves a network failure or a refused password out of the unknown database case', function (Throwable $e): void {
    expect(ConnectionFailure::unknownDatabase($e))->toBeFalse();
})->with([
    'mysql refused' => [new PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002)],
    'mysql password' => [new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost' (using password: YES)", 1045)],
    'pgsql timeout' => [new PDOException('SQLSTATE[08006] [7] connection to server at "10.0.0.9", port 5432 failed: timeout expired')],
    'not a driver error' => [new RuntimeException('Disk full', 1049)],
]);
