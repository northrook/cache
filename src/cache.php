<?php

declare(strict_types=1);

namespace Cache;

use Closure;
use Support\Normalize;
use function String\hashKey;

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
 * @template Type
 *
 * @param array{0:class-string, 1:string}|callable():Type|Closure():Type $callback    a function or method to cache, optionally with extra arguments as array values
 * @param ?string                                                        $key         [optional] Key - a hash based on $callback and $arguments will be used if null
 * @param ?int                                                           $persistence the duration in seconds for the cache entry
 *
 * @return Type
 * @phpstan-return Type
 */
function memoize(
    Closure|callable|array $callback,
    ?string                $key = null,
    ?int                   $persistence = EPHEMERAL,
) : mixed {
    return MemoizationCache::instance()->set( $callback, $key, $persistence );
}

function key( string|array|null $string, bool $hash = false ) : string
{
    return $hash ? hashKey( $string ) : Normalize::key( $string );
}
