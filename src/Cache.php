<?php

namespace Northrook;

use Northrook\Core\Trait\StaticClass;

/**
 * @author Martin Nielsen <mn@northrook.com>
 */
final  class Cache
{
    use StaticClass;

    private static bool $initialized = false;

    public const EPHEMERAL = null;
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