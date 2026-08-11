<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Diff;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Persists the pre-migration schema snapshot (the diff baseline) as a
 * structure-only JSON file on a configurable Laravel disk.
 *
 * The baseline lives on disk, not in the cache, because it is the one piece of
 * state Truss cannot rebuild from the live database: once the migration has run,
 * the previous schema exists nowhere else. It stores the same structure-only
 * snapshot the cache holds, so it never records row data. The file is derived and
 * safe to delete; a missing baseline simply yields an empty diff until the next
 * migration re-seeds it.
 */
class BaselineStore
{
    /**
     * Why the last operation failed, or null when it succeeded. Callers that can
     * say something useful (the CLI) read this; callers that cannot (the HTTP
     * endpoint, the migration listener) ignore it and carry on.
     */
    private ?string $lastError = null;

    /**
     * @param  array<string, mixed>  $snapshot
     * @return bool false when the baseline could not be written
     */
    public function save(string $connection, array $snapshot): bool
    {
        return $this->attempt(function () use ($connection, $snapshot): bool {
            $this->disk()->put(
                $this->path($connection),
                json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            );

            return true;
        }, false);
    }

    /**
     * @return array<string, mixed>|null null when no baseline is stored, or when
     *                                   the disk could not be read
     */
    public function get(string $connection): ?array
    {
        return $this->attempt(function () use ($connection): ?array {
            $disk = $this->disk();
            $path = $this->path($connection);

            if (! $disk->exists($path)) {
                return null;
            }

            $raw = $disk->get($path);

            if ($raw === null || $raw === '') {
                return null;
            }

            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }, null);
    }

    public function has(string $connection): bool
    {
        return $this->attempt(fn (): bool => $this->disk()->exists($this->path($connection)), false);
    }

    /**
     * @return bool false when the baseline could not be deleted
     */
    public function forget(string $connection): bool
    {
        return $this->attempt(function () use ($connection): bool {
            $this->disk()->delete($this->path($connection));

            return true;
        }, false);
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Run a disk operation, degrading to $fallback instead of throwing.
     *
     * The baseline is the only part of Truss that touches the filesystem, and it
     * serves one secondary feature. Letting a storage error escape put it in a
     * position to break things that do not depend on it: the schema endpoint
     * (which needs no filesystem at all) returned 500, and the MigrationsEnded
     * listener could fail `php artisan migrate` outright, since Laravel does not
     * swallow listener exceptions. A missing baseline is an empty diff, so that
     * is what every failure degrades to.
     *
     * Throwable rather than a narrower type on purpose: a remote adapter throws
     * Flysystem exceptions, an unconfigured disk name throws
     * InvalidArgumentException, and a corrupt baseline throws JsonException.
     * All three mean the same thing here, and none should reach the caller.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @param  T  $fallback
     * @return T
     */
    private function attempt(callable $operation, mixed $fallback): mixed
    {
        try {
            $result = $operation();
            $this->lastError = null;

            return $result;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return $fallback;
        }
    }

    private function path(string $connection): string
    {
        return 'truss/baselines/'.$this->slug($connection).'.json';
    }

    /**
     * Reduce a connection name to a filesystem-safe filename, so a name with
     * slashes or colons can never traverse out of the baselines directory.
     */
    private function slug(string $connection): string
    {
        $slug = Str::slug($connection);

        return $slug !== '' ? $slug : 'default';
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('truss.diff.disk'));
    }
}
