<?php

use Northrook\Cache;
use Northrook\CacheManager;
use Symfony\Contracts\Cache\ItemInterface;

function Cached(
    callable    $callback,
    array       $arguments = [],
    false | int $ttl = false,
) : mixed {

    return CacheManager::memoAdapter( $ttl )->get(
        Cache::key( $arguments ),
        static function ( ItemInterface $memo ) use ( $callback, $arguments, $ttl ) : mixed {

            if ( $ttl !== false ) {
                $memo->expiresAfter( $ttl );
            }

            return $callback( ...$arguments );
        },
    );
}