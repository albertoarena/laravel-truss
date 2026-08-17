<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Cache;

use AlbertoArena\Truss\Introspection\SnapshotBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reads and writes the schema snapshot via Laravel's Cache facade, keyed per
 * connection and respecting the configured TTL. The snapshot is derived,
 * disposable data — no database table is used to store it.
 *
 * This is the layer that stamps `generated_at`: the introspection layer stays
 * deterministic and timestamp-free, and the envelope timestamp is added here.
 *
 * Every cache operation degrades instead of throwing, for the same reason
 * BaselineStore does it for the filesystem: the store is somebody else's
 * infrastructure, and an unusable one must not be able to break things that do
 * not depend on it. The snapshot is always rebuildable from the live database,
 * so a broken store costs speed, never correctness. `lastError()` lets a caller
 * that can act on it say something useful.
 */
class SchemaCacheRepository
{
    public function __construct(
        private readonly SnapshotBuilder $builder = new SnapshotBuilder,
    ) {}

    /**
     * Why the last cache operation failed, or null when it succeeded. The HTTP
     * endpoint turns this into a payload flag, the commands print it, and the
     * migration listener logs it.
     */
    private ?string $lastError = null;

    /**
     * Get the cached snapshot for a connection, building and caching it on miss.
     *
     * Always returns a usable snapshot: when the cache store cannot be read, the
     * schema is built live and simply not cached, which is slower but complete.
     *
     * @return array<string, mixed>
     */
    public function get(?string $connection = null): array
    {
        $connection = $this->resolve($connection);
        $key = $this->key($connection);

        $cached = $this->attempt(fn (): mixed => Cache::get($key), null);

        if (is_array($cached)) {
            return $cached;
        }

        // A failed read and an empty cache are the same thing to us (build it),
        // but they are not the same thing to the caller, so the reason survives
        // the write attempt that follows.
        $readError = $this->lastError;

        $snapshot = $this->build($connection);
        $this->store($key, $snapshot);

        // The read error wins when both failed: it is the diagnostic one ("no
        // such table: cache"), while the write error is the same fact restated
        // around a query containing the entire serialized snapshot.
        $this->lastError = $readError ?? $this->lastError;

        return $snapshot;
    }

    /**
     * Force a rebuild and overwrite the cache, returning the fresh snapshot.
     *
     * The snapshot is returned even when it could not be stored, so callers that
     * only need the structure carry on; `lastError()` reports the failed write to
     * the ones that care (truss:rebuild fails on it).
     *
     * @return array<string, mixed>
     */
    public function rebuild(?string $connection = null): array
    {
        $connection = $this->resolve($connection);
        $snapshot = $this->build($connection);

        $this->store($this->key($connection), $snapshot);

        return $snapshot;
    }

    /**
     * @return bool false when the snapshot could not be forgotten
     */
    public function forget(?string $connection = null): bool
    {
        return $this->attempt(function () use ($connection): bool {
            Cache::forget($this->key($this->resolve($connection)));

            return true;
        }, false);
    }

    public function has(?string $connection = null): bool
    {
        return $this->attempt(
            fn (): bool => Cache::has($this->key($this->resolve($connection))),
            false,
        );
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Read the currently cached snapshot without building on a miss, returning
     * null when nothing is cached. Used to capture the pre-migration snapshot as
     * a diff baseline: unlike get(), it never reaches the live database, so it
     * cannot accidentally record the post-migration state.
     *
     * @return array<string, mixed>|null
     */
    public function peek(?string $connection = null): ?array
    {
        return $this->attempt(
            fn (): ?array => Cache::get($this->key($this->resolve($connection))),
            null,
        );
    }

    /**
     * The connections Truss manages: those configured under truss.connections,
     * or the application's default connection when none are configured.
     *
     * @return list<string>
     */
    public function managedConnections(): array
    {
        $configured = array_keys((array) config('truss.connections', []));

        return $configured !== [] ? array_map(strval(...), $configured) : [$this->resolve(null)];
    }

    public function key(string $connection): string
    {
        return "truss:schema:{$connection}";
    }

    /**
     * Write the snapshot, honouring the TTL (0 or less means forever).
     *
     * @param  array<string, mixed>  $snapshot
     * @return bool false when the store could not be written
     */
    private function store(string $key, array $snapshot): bool
    {
        $ttl = $this->ttl();

        return $this->attempt(function () use ($key, $snapshot, $ttl): bool {
            $ttl <= 0 ? Cache::forever($key, $snapshot) : Cache::put($key, $snapshot, $ttl);

            return true;
        }, false);
    }

    /**
     * Run a cache operation, degrading to $fallback instead of throwing.
     *
     * Throwable rather than a narrower type on purpose: the database store throws
     * QueryException (most often "no such table: cache", since CACHE_STORE=database
     * is Laravel's own default and the table may not exist yet), Redis and
     * Memcached throw their own connection exceptions, and an unconfigured store
     * name throws InvalidArgumentException. They all mean the same thing here.
     *
     * Logged at debug, not warning: a dashboard request while the store is down
     * would otherwise flood the log, and the response already carries the flag
     * that tells a human. The migration listener, which has no other way to
     * report, logs its own warning.
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
            // Trimmed because a failed write echoes its query back, and that query
            // carries the whole serialized snapshot: the raw message can be tens
            // of kilobytes, and it goes to a terminal and to the log.
            $this->lastError = Str::limit($e->getMessage(), 180);

            Log::debug('Truss could not reach the cache store: '.$this->lastError);

            return $fallback;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function build(string $connection): array
    {
        $snapshot = $this->builder->build($connection);
        $snapshot['generated_at'] = now()->toIso8601String();

        return $snapshot;
    }

    private function resolve(?string $connection): string
    {
        return $connection ?? (string) config('database.default');
    }

    private function ttl(): int
    {
        return (int) config('truss.cache.ttl', 3600);
    }
}
