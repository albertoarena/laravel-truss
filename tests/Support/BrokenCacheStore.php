<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Tests\Support;

use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * A cache store where every operation throws, standing in for a store whose
 * backing service is not usable: `CACHE_STORE=database` before the `cache` table
 * exists, or a Redis/Memcached server that is down.
 *
 * Driver-agnostic on purpose. The real drivers throw different exception types
 * (QueryException, RedisException, a Memcached failure), and Truss treats them
 * all the same, so the tests should not depend on any one of them. It throws on
 * writes as well as reads, because the write path (rebuild) is exposed too.
 */
class BrokenCacheStore implements Store
{
    public const MESSAGE = 'the cache store is unavailable';

    public function get($key): mixed
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function many(array $keys): array
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function put($key, $value, $seconds): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function putMany(array $values, $seconds): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function increment($key, $value = 1): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function decrement($key, $value = 1): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function forever($key, $value): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function forget($key): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function touch($key, $ttl): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function flush(): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function getPrefix(): string
    {
        return '';
    }
}
