<?php

namespace Northrook;

use Northrook\Core\Trait\StaticClass;
use Symfony\Contracts\Cache\ItemInterface;
use function Northrook\Core\hashKey;

/**
 * @author Martin Nielsen <mn@northrook.com>
 */
final class Cache
{
    use StaticClass;

    public const EPHEMERAL = -1;
    public const AUTO      = null;
    public const FOREVER   = 0;
    public const MINUTE    = 60;
    public const HOUR      = 3600;
    public const HOUR_4    = 14400;
    public const HOUR_8    = 28800;
    public const HOUR_12   = 43200;
    public const DAY       = 86400;
    public const WEEK      = 604800;
    public const MONTH     = 2592000;
    public const YEAR      = 31536000;

    /**
     * - Not encrypted
     * - Not persistent
     *
     * @var array
     */
    private static array $memoCache = [];

    /**
     * Retrieve the status of the in-memory memo cache.
     *
     * @return array
     */
    public static function status() : array {
        return Cache::$memoCache;
    }

    /**
     * Memoize a callback.
     *
     * - Can persist between requests
     * - Can be encrypted by the Adapter
     *
     * @param callable     $callback
     * @param array        $arguments
     * @param null|string  $key
     * @param null|int     $persistence
     *
     * @return mixed
     */
    public static function memoize(
        callable $callback,
        array    $arguments = [],
        ?string  $key = null,
        ?int     $persistence = Cache::EPHEMERAL,
    ) : mixed {

        if (
            Cache::EPHEMERAL === $persistence &&
            !CacheManager::setting( 'memo.ephemeral.preferArrayAdapter' )
        ) {
            return Cache::memo( $callback, $arguments, $key );
        }

        $persistence ??= CacheManager::setting( 'memo.ttl' );

        return CacheManager::memoAdapter( $persistence )->get(
            key      : $key ?? hashKey( $arguments ),
            callback : static function ( ItemInterface $memo ) use (
                $callback, $arguments, $persistence,
            ) : mixed {
                $memo->expiresAfter( $persistence );
                return $callback( ...$arguments );
            },
        );
    }

    /**
     * Simple in-memory cache.
     *
     * @param callable     $callback
     * @param array        $arguments
     * @param null|string  $key
     *
     * @return mixed
     */
    public static function memo(
        callable $callback,
        array    $arguments = [],
        ?string  $key = null,
    ) : mixed {

        $key ??= hashKey( $arguments );

        if ( !isset( Cache::$memoCache[ $key ] ) ) {
            Cache::$memoCache[ $key ] = [
                'value' => $callback( ...$arguments ),
                'hit'   => 0,
            ];
        }
        else {
            Cache::$memoCache[ $key ][ 'hit' ]++;
        }

        return Cache::$memoCache[ $key ][ 'value' ];
    }
}