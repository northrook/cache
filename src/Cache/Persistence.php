<?php

declare( strict_types = 1 );

namespace Northrook\Cache;

enum Persistence : int
{
    case AUTO = -2;             // Defers to Cache::setting
    case EPHEMERAL = -1;        // Cache only for this request
    case FOREVER = 0;           // Store until deleted, or regenerated due to miss
    case MINUTE = 60;           // 1 minute
    case HOUR = 3600;           // 1 hour
    case DAY = 86400;           // 1 day
    case WEEK = 604800;         // 1 week
    case MONTH = 2592000;       // 1 month
    case SIX_MONTHS = 15552000; // 6 months
    case YEAR = 31536000;       // 1 year

    public function ttl() : ?int
    {
        return match ( $this ) {
            Persistence::EPHEMERAL => null,
            Persistence::FOREVER   => 0,
            default                => $this->value,
        };
    }

}