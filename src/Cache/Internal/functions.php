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
    $cached = CacheManager::memoAdapter( $persistence )->get(
        key      : $cacheKey,
        callback : static function ( ItemInterface $memo ) use (
            $callback, $arguments, $persistence,
        ) : mixed {
            $memo->expiresAfter( $persistence );

            $value = $callback( ...$arguments );

            CacheManager::status( $memo->getKey(), true );

            return $value;
        },
    );

    CacheManager::status( $cacheKey );
    return $cached;
}