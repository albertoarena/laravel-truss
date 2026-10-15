<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Cli;

/**
 * What `truss --version` prints.
 *
 * There is no version constant anywhere else in this package, and that is
 * deliberate: the git tag is the only source of truth and `composer.json`
 * carries no version, so nothing can go stale. The binary cannot read a tag at
 * runtime, so the build stamps one into the constant below and an unbuilt
 * checkout has to behave sensibly without it.
 *
 * **Anything starting with `@` is an unreplaced placeholder**, which is the
 * state of every clone and of the whole test suite. The check is the leading
 * character rather than equality with the placeholder, because the build
 * substitutes a token wherever it appears, including in a constant written to
 * hold it for comparison.
 */
final class Version
{
    /** What an unbuilt stamp looks like. The build rewrites it. */
    public const PLACEHOLDER = '@truss_version@';

    /**
     * Stamped at build time by Box.
     *
     * Keep this on its own line and keep the literal exactly as written: the
     * build does a textual substitution, so reformatting it is enough to make
     * every built binary report "dev".
     */
    private const STAMP = '@truss_version@';

    public static function current(): string
    {
        return self::resolve(self::STAMP);
    }

    public static function resolve(string $stamp): string
    {
        $stamp = trim($stamp);

        // An empty stamp means a build substituted nothing, which is a broken
        // build. Reporting it as "dev" rather than as a blank keeps a bug
        // report from quoting a version that never shipped.
        if ($stamp === '' || str_starts_with($stamp, '@')) {
            return 'dev';
        }

        return $stamp;
    }
}
