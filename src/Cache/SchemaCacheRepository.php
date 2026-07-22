<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Cache;

use AlbertoArena\Truss\Introspection\SnapshotBuilder;
use Illuminate\Support\Facades\Cache;

/**
 * Reads and writes the schema snapshot via Laravel's Cache facade, keyed per
 * connection and respecting the configured TTL. The snapshot is derived,
 * disposable data — no database table is used to store it.
 *
 * This is the layer that stamps `generated_at`: the introspection layer stays
 * deterministic and timestamp-free, and the envelope timestamp is added here.
 */
class SchemaCacheRepository
{
    public function __construct(
        private readonly SnapshotBuilder $builder = new SnapshotBuilder,
    ) {}

    /**
     * Get the cached snapshot for a connection, building and caching it on miss.
     *
     * @return array<string, mixed>
     */
    public function get(?string $connection = null): array
    {
        $connection = $this->resolve($connection);
        $key = $this->key($connection);
        $ttl = $this->ttl();

        if ($ttl <= 0) {
            return Cache::rememberForever($key, fn (): array => $this->build($connection));
        }

        return Cache::remember($key, $ttl, fn (): array => $this->build($connection));
    }

    /**
     * Force a rebuild and overwrite the cache, returning the fresh snapshot.
     *
     * @return array<string, mixed>
     */
    public function rebuild(?string $connection = null): array
    {
        $connection = $this->resolve($connection);
        $snapshot = $this->build($connection);
        $key = $this->key($connection);
        $ttl = $this->ttl();

        $ttl <= 0 ? Cache::forever($key, $snapshot) : Cache::put($key, $snapshot, $ttl);

        return $snapshot;
    }

    public function forget(?string $connection = null): void
    {
        Cache::forget($this->key($this->resolve($connection)));
    }

    public function has(?string $connection = null): bool
    {
        return Cache::has($this->key($this->resolve($connection)));
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
