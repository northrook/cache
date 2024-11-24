<?php

declare(strict_types=1);

namespace Cache;

use Closure;

const DISABLED  = -2;
const EPHEMERAL = -1;
const AUTO      = null;
const FOREVER   = 0;

/**
 * Cache the result of a callable, improving  performance by avoiding redundant computations.
 *
 * Utilizing the {@see MemoizationCache}, the `$callback` is stored using:
 * - A Symfony {@see \Symfony\Contracts\Cache\CacheInterface} if provided
 * - Any {@see \Psr\Cache\CacheItemPoolInterface} if provided
 * - Otherwise using an ephemeral {@see \Cache\MemoizationCache::$inMemoryCache}
 *
 * If the `memoize()` function is called before `MemoizationCache` has been initialized,  it will self-initialize and use the `$inMemoryCache`.
 *
 * Potential later initializations will import the `$inMemoryCache`.
 *
 * @param Closure $callback    The function to cache
 * @param ?string $key         [optional] key - a hash based on $callback and $arguments will be used if null
 * @param ?int    $persistence The duration in seconds for the cache entry. Requires a {@see \Psr\Cache\CacheItemPoolInterface}.
 *
 * @return mixed
 */
function memoize(
    Closure $callback,
    ?string $key = null,
    ?int    $persistence = EPHEMERAL,
) : mixed {
    return MemoizationCache::instance()->cache( $callback, $key, $persistence );
}
