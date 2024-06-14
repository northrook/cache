<?php

namespace Northrook;

class Cache
{
    private static bool $initialized = false;

    public const TTL_FOREVER = 0;
    public const TTL_MINUTE  = 60;
    public const TTL_HOUR    = 3600;
    public const TTL_HOUR_4  = 14400;
    public const TTL_HOUR_8  = 28800;
    public const TTL_HOUR_12 = 43200;
    public const TTL_DAY     = 86400;
    public const TTL_WEEK    = 604800;
    public const TTL_MONTH   = 2592000;
    public const TTL_YEAR    = 31536000;


    private static array $settings = [
        'ttl'          => Cache::TTL_HOUR_4,
        'ttl.memo'     => Cache::TTL_MINUTE,
        'ttl.manifest' => Cache::TTL_FOREVER,
    ];

    public function __construct( array $settings = []) {
        if ( Cache::$initialized ) {
            throw new \LogicException(
                'The ' . Cache::class . ' has already been instantiated. 
                It cannot be re-instantiated.',
            );
        }

        Cache::$settings = array_merge( Cache::$settings, $settings );
        Cache::$initialized = true;
    }

    /**
     * @param string  $get = ['ttl', 'ttl.memo', 'ttl.manifest']
     *
     * @return bool|int|string
     */
    public static function setting( string $get)  : bool | int | string {
        return Cache::$settings[ $get ] ?? false;
    }

    // public static function memoize( callable $callback, array $arguments = [], false | int $persistFor = false ) : mixed {
    //
    // }

    /**
     * Generate a unique key from provided arguments.
     *
     * @param mixed  $arguments
     *
     * @return string
     */
    public static function key( mixed $arguments ) : string {
        return hash( 'xxh3', json_encode( $arguments ) ?: serialize( $arguments ) );
    }
}