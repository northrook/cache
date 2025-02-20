<?php

declare(strict_types=1);

namespace Cache;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use LogicException;

trait CachePoolTrait
{
    /** @var array<string, mixed>|CacheItemPoolInterface */
    protected CacheItemPoolInterface|array $cache = [];

    /**
     * Sets the {@see CacheItemPoolInterface}.
     *
     * - Will not override already-set Adapters
     * - Will override the in-memory array cache
     *
     * @param CacheItemPoolInterface $cache `PSR-6` cache adapter
     *
     * @return void
     */
    final public function setCacheAdapter( CacheItemPoolInterface $cache ) : void
    {
        if ( $this->cache instanceof CacheItemPoolInterface ) {
            return;
        }
        $this->cache = $cache;
    }

    /**
     * @template Value
     *
     * @param string            $key
     * @param ?callable():Value $callback
     * @param null|mixed        $fallback
     *
     * @return mixed|Value
     */
    final protected function getCache(
        string    $key,
        ?callable $callback = null,
        mixed     $fallback = null,
    ) : mixed {
        if ( \is_array( $this->cache ) ) {
            if ( isset( $this->cache[$key] ) ) {
                return $this->cache[$key];
            }

            if ( ! $value = \is_callable( $callback ) ? $callback() : null ) {
                return $fallback;
            }

            return $this->cache[$key] = $value;
        }

        try {
            if ( $this->cache->hasItem( $key ) ) {
                return $this->cache->getItem( $key )->get();
            }

            if ( ! $value = \is_callable( $callback ) ? $callback() : null ) {
                return $fallback;
            }

            return $this->cache->getItem( $key )->set( $value )->get();
        }
        catch ( Throwable $exception ) {
            $this->handleLocalCacheException( __METHOD__, $key, $exception );
        }

        return $fallback;
    }

    final protected function setCache( string $key, string $value ) : void
    {
        if ( \is_array( $this->cache ) ) {
            $this->cache[$key] = $value;
            return;
        }

        try {
            $item = $this->cache->getItem( $key );
            $item->set( $value );
        }
        catch ( Throwable $exception ) {
            $this->handleLocalCacheException( __METHOD__, $key, $exception );
        }
    }

    final protected function unsetCache( string $key ) : void
    {
        if ( \is_array( $this->cache ) ) {
            unset( $this->cache[$key] );
            return;
        }

        try {
            $this->cache->deleteItem( $key );
        }
        catch ( Throwable $exception ) {
            $this->handleLocalCacheException( __METHOD__, $key, $exception );
        }
    }

    final protected function clearCache() : void
    {
        if ( \is_array( $this->cache ) ) {
            $this->cache = [];
            return;
        }

        try {
            $this->cache->clear();
        }
        catch ( Throwable $exception ) {
            $this->handleLocalCacheException( __METHOD__, $key, $exception );
        }
    }

    private function handleLocalCacheException(
        string    $caller,
        string    $key,
        Throwable $exception,
    ) : void {
        if ( \property_exists( $this, 'logger' )
             && $this->logger instanceof LoggerInterface
        ) {
            $this->logger->error(
                "{$caller}: {key}. ".$exception->getMessage(),
                ['key' => $key, 'exception' => $exception],
            );
        }
        else {
            throw new LogicException(
                "{$caller}: {$key}. ".$exception->getMessage(),
                $exception->getCode(),
                $exception,
            );
        }
    }
}
