<?php

declare(strict_types=1);

namespace Cache;

use Psr\Cache\CacheItemPoolInterface;
use const Support\CACHE_AUTO;

/**
 * @phpstan-require-implements \Cache\Interface\SettableCachePoolInterface
 */
trait SettableCachePoolTrait
{
    use CachePoolTrait;

    /**
     * Sets the {@see CacheItemPoolInterface}.
     *
     * - Will not override already-set Adapters
     * - Will override the in-memory array cache
     *
     * @param null|CacheItemPoolInterface $adapter    `PSR-6` cache adapter
     * @param null|string                 $prefix     [optional] `prefix.key`
     * @param bool                        $defer
     * @param null|int                    $expiration
     *
     * @return void
     */
    public function setCacheAdapter(
        ?CacheItemPoolInterface $adapter,
        ?string                 $prefix = null,
        bool                    $defer = false,
        ?int                    $expiration = CACHE_AUTO,
    ) : void {
        $this->assignCacheAdapter( $adapter, $prefix, $defer, $expiration );
    }
}
