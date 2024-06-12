<?php

declare(strict_types=1);

namespace Northrook\Cache;

use Northrook\Cache\Internal\Timestamp;

abstract class AbstractCache
{
    final protected function timestamp() : Timestamp{
        return new Timestamp();
    }

    final protected function getSystemCacheDirectory() : string {
        return sys_get_temp_dir();
    }
}