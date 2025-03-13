<?php

namespace Cache\Interface;

use Psr\Cache\CacheItemPoolInterface;
use const Support\CACHE_AUTO;

interface SettableCachePoolInterface
{
    /**
     * Sets the {@see CacheItemPoolInterface}.
     *
     * - Will not override already-set Adapters
     * - Will override the in-memory array cache
     *
     * @param ?CacheItemPoolInterface $cache      `PSR-6` cache adapter
     * @param ?string                 $prefix     [optional] `prefix.key`
     * @param bool                    $defer
     * @param ?int                    $expiration
     *
     * @return void
     */
    public function setCacheAdapter(
        ?CacheItemPoolInterface $cache,
        ?string                 $prefix = null,
        bool                    $defer = false,
        ?int                    $expiration = CACHE_AUTO,
    ) : void;
}
