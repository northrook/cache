<?php

declare( strict_types = 1 );

use Northrook\Cache;
use Northrook\Cache\Persistence;
use Northrook\CacheManager;
use Symfony\Contracts\Cache\ItemInterface;

function Cached(
    callable $callback,
    array    $arguments = [],
    ?int     $persistence = null,
) : mixed {
    $cacheKey = Cache::key( $arguments );
    CacheManager::status($cacheKey );
    return CacheManager::memoAdapter( $persistence,$cacheKey )->get(
        key      : $cacheKey,
        callback : static function ( ItemInterface $memo) use (
            $callback, $arguments, $persistence, $cacheKey
        ) : mixed {
            $memo->expiresAfter( $persistence );
            $value = $callback( ...$arguments );

            CacheManager::status( $cacheKey, true );

            return $value;
        },
    );
}