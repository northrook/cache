<?php

namespace Cache\Interface;

use _Dev\Attribute\Experimental;
use Core\Interface\ProfilerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use const Support\CACHE_AUTO;

#[Experimental]
interface Cachable
{
    /**
     * Sets the {@see CacheItemPoolInterface}.
     *
     * - Will not override already-set Adapters
     * - Will override the in-memory array cache
     *
     * @param null|CacheItemPoolInterface           $adapter    `PSR-6` cache adapter
     * @param null|string                           $prefix     [optional] `prefix.key`
     * @param bool                                  $defer
     * @param null|int                              $expiration
     * @param null|bool|ProfilerInterface|Stopwatch $profiler
     *
     * @return void
     */
    public function setCacheAdapter(
        ?CacheItemPoolInterface               $adapter,
        ?string                               $prefix = null,
        bool                                  $defer = false,
        ?int                                  $expiration = CACHE_AUTO,
        null|bool|Stopwatch|ProfilerInterface $profiler = null,
    ) : void;
}
