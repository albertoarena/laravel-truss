<?php

/*
 * The PHP floor guard, and the first thing the binary runs.
 *
 * Deliberately not a class, not namespaced, and written in PHP 7 syntax, for
 * one reason each.
 *
 * **Not a class**, because this runs before the Composer autoloader. Loading
 * the autoloader loads class files written for the PHP this check exists to
 * rule out, and a parse error there happens before any code of ours can
 * explain itself.
 *
 * **PHP 7 syntax**, because the interpreter this runs on is the user's own.
 * The Homebrew formula installs none, so the version is whatever `php` resolves
 * to on their machine, and on an old one a modern syntax error in the guard is
 * strictly worse than the failure it was written to prevent.
 *
 * **No `declare(strict_types=1)`** for the same reason it takes no types: it is
 * called with `PHP_VERSION` and a literal, and nothing here benefits.
 */

if (! function_exists('truss_php_floor_failure')) {
    /**
     * One sentence when the running PHP is too old, or null when it will do.
     *
     * @param  string  $found  normally PHP_VERSION
     * @param  string  $required  the floor, as a major.minor string
     * @return string|null
     */
    function truss_php_floor_failure($found, $required)
    {
        if (version_compare($found, $required, '>=')) {
            return null;
        }

        // Both versions, because neither alone tells the reader what to do:
        // the floor without what they have reads like a generic requirement,
        // and what they have without the floor leaves them guessing. One line,
        // since this is printed raw to stderr and may be all they see.
        return 'Truss needs PHP '.$required.' or newer, found '.$found.'. Install a newer PHP, or run Truss inside a Laravel application with the package instead.';
    }
}
