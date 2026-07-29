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
     * @param  array<string, mixed>  $snapshot
     */
    public function save(string $connection, array $snapshot): void
    {
        $this->disk()->put(
            $this->path($connection),
            json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>|null null when no baseline is stored
     */
    public function get(string $connection): ?array
    {
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
    }

    public function has(string $connection): bool
    {
        return $this->disk()->exists($this->path($connection));
    }

    public function forget(string $connection): void
    {
        $this->disk()->delete($this->path($connection));
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
