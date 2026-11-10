<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Cli;

use PDOException;
use Throwable;

/**
 * Why a connection could not be opened, as far as the binary needs to know.
 *
 * Pure: it reads an exception and opens nothing.
 *
 * **Two questions only: did the server answer and refuse the credentials, or
 * answer and say the database is not there?** Those are the cases the "could
 * not reach" message gets wrong, sending somebody with a mistyped password or
 * database name off to check the VPN. Everything else, including errors nobody
 * has seen yet, stays "could not reach", so a new driver message can only ever
 * fall back to the old wording, never to a wrong new one.
 *
 * - **MySQL and MariaDB** answer a refused login with driver error 1045 ("Access
 *   denied for user") and a missing database with 1049 ("Unknown database").
 *   PDO puts the error in the code and in brackets in the message; either
 *   counts, since a wrapper can lose the code.
 * - **PostgreSQL** reports every failed connection as SQLSTATE 08006, so the
 *   state cannot tell a wrong password from a dead host. The server's own
 *   sentence can: "password authentication failed", or `role "x" does not
 *   exist` from a server that never asks for a password, and `database "x" does
 *   not exist`. The proper states, 28P01 and 3D000, count too wherever a server
 *   or wrapper does send them.
 */
final class ConnectionFailure
{
    public static function refusedCredentials(Throwable $e): bool
    {
        return self::anyInChain($e, self::isRefusal(...));
    }

    public static function unknownDatabase(Throwable $e): bool
    {
        return self::anyInChain($e, self::isUnknownDatabase(...));
    }

    /**
     * @param  callable(Throwable): bool  $test
     */
    private static function anyInChain(Throwable $e, callable $test): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($test($current)) {
                return true;
            }
        }

        return false;
    }

    private static function isRefusal(Throwable $e): bool
    {
        $message = $e->getMessage();

        return ($e instanceof PDOException && (int) $e->getCode() === 1045)
            || str_contains($message, '[1045]')
            || str_contains($message, '28P01')
            || str_contains($message, 'password authentication failed')
            || preg_match('/\brole ".*" does not exist/', $message) === 1;
    }

    private static function isUnknownDatabase(Throwable $e): bool
    {
        $message = $e->getMessage();

        return ($e instanceof PDOException && (int) $e->getCode() === 1049)
            || str_contains($message, '[1049]')
            || str_contains($message, '3D000')
            || preg_match('/\bdatabase ".*" does not exist/', $message) === 1;
    }
}
