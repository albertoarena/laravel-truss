<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every global function the source calls, and where it calls it from.
 *
 * It exists because a hand-written list of the Foundation helpers the PHAR has
 * to carry was wrong. It named `config()`, `app()` and `database_path()` and
 * missed `now()`, which `SchemaCacheRepository` uses to stamp a snapshot, so
 * every run of the binary would have fatalled on its first cache write. The
 * list was assembled by grepping for names somebody had thought of, which is
 * the one method guaranteed to miss the name nobody thought of.
 *
 * Tokens rather than a regular expression, so a method call, a static call, a
 * declaration and a `new` are excluded structurally instead of by a character
 * class that has to anticipate each of them.
 */
final class FunctionInventory
{
    /**
     * @return array<string, list<string>> function name to the files calling it
     */
    public static function in(string $directory): array
    {
        $calls = [];

        foreach (self::files($directory) as $file) {
            foreach (self::callsIn((string) file_get_contents($file->getPathname())) as $name) {
                $calls[$name][str_replace($directory.'/', '', $file->getPathname())] = true;
            }
        }

        ksort($calls);

        return array_map(static fn (array $files): array => array_keys($files), $calls);
    }

    /**
     * @return list<SplFileInfo>
     */
    private static function files(string $directory): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function callsIn(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $names = [];

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING) {
                continue;
            }

            if (! self::isFollowedByCall($tokens, $i, $count) || self::isQualified($tokens, $i)) {
                continue;
            }

            $names[] = strtolower($tokens[$i][1]);
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<array{0: int, 1: string}|string>  $tokens
     */
    private static function isFollowedByCall(array $tokens, int $i, int $count): bool
    {
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }

            return $tokens[$j] === '(';
        }

        return false;
    }

    /**
     * True when the name belongs to something other than a call to a global
     * function: a method, a static member, a declaration, a class name after
     * `new`, or part of a namespaced name.
     *
     * @param  list<array{0: int, 1: string}|string>  $tokens
     */
    private static function isQualified(array $tokens, int $i): bool
    {
        for ($k = $i - 1; $k >= 0; $k--) {
            if (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                continue;
            }

            if (is_array($tokens[$k])) {
                return in_array($tokens[$k][0], [
                    T_OBJECT_OPERATOR,
                    T_NULLSAFE_OBJECT_OPERATOR,
                    T_DOUBLE_COLON,
                    T_FUNCTION,
                    T_NEW,
                    T_STRING,
                    T_NAME_QUALIFIED,
                    T_NAME_FULLY_QUALIFIED,
                    T_ATTRIBUTE,
                ], true);
            }

            return $tokens[$k] === '$';
        }

        return false;
    }
}
