<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cli\Support\Helpers;
use Illuminate\Support\Carbon;

/*
 * The Foundation helpers the PHAR has to carry, because Foundation is not a
 * publishable component and `src/` calls `config()` 53 times.
 *
 * Loaded through `autoload.files` in build/composer.json, so it is in place
 * before any Truss class runs. Two rules it must keep.
 *
 * **Every definition is guarded.** A clone of this repository has
 * laravel/framework installed, so Foundation defines both of these, and an
 * unguarded definition is a fatal "Cannot redeclare config()" rather than a
 * test failure. In the built PHAR the guards are always false and these
 * definitions are the ones that run.
 *
 * **The bodies delegate and hold no logic.** The behaviour lives in Helpers,
 * where a test can reach it even when Foundation has won the name here.
 */

if (! function_exists('config')) {
    /**
     * @param  array<string, mixed>|string|null  $key
     */
    function config(array|string|null $key = null, mixed $default = null): mixed
    {
        return Helpers::config($key, $default);
    }
}

if (! function_exists('now')) {
    function now(DateTimeZone|string|null $timezone = null): Carbon
    {
        return Helpers::now($timezone);
    }
}

if (! function_exists('app')) {
    /**
     * @param  array<string, mixed>  $parameters
     */
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        return Helpers::app($abstract, $parameters);
    }
}
