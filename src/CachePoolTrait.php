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
     * @param ?string           $key
     * @param ?callable():Value $callback
     * @param null|mixed        $fallback
     * @param bool              $defer
     *
     * @return mixed|Value
     */
    protected function getCache(
        ?string   $key,
        ?callable $callback = null,
        mixed     $fallback = null,
        bool      $defer = false,
    ) : mixed {
        if ( ! $key ) {
            return $fallback;
        }

        $arrayCache = \is_array( $this->cache );

        if ( $this->hasCache( $key ) ) {
            if ( $arrayCache ) {
                return $this->cache[$key];
            }
            try {
                return $this->cache->getItem( $key )->get();
            }
            catch ( Throwable $exception ) {
                $this->handleLocalCacheException( __METHOD__, $key, $exception );
            }
        }

        if ( ! $value = \is_callable( $callback ) ? $callback() : null ) {
            return $fallback;
        }

        if ( $arrayCache ) {
            return $this->cache[$key] = $value;
        }

        $this->setCache( $key, $value, $defer );

        return $value;
    }

    protected function hasCache( ?string $key ) : bool
    {
        if ( ! $key ) {
            return false;
        }

        if ( \is_array( $this->cache ) ) {
            return isset( $this->cache[$key] );
        }

        try {
            return $this->cache->hasItem( $key );
        }
        catch ( Throwable $exception ) {
            $this->handleLocalCacheException( __METHOD__, $key, $exception );
        }

        return false;
    }

    protected function setCache( string $key, mixed $value, bool $defer = false ) : void
    {
        if ( \is_array( $this->cache ) ) {
            $this->cache[$key] = $value;
            return;
        }

        try {
            $item = $this->cache->getItem( $key );
            $item->set( $value );
            if ( $defer ) {
                $this->cache->saveDeferred( $item );
            }
            else {
                $this->cache->save( $item );
            }
        }
        catch ( Throwable $exception ) {
            $this->handleLocalCacheException( __METHOD__, $key, $exception );
        }
    }

    protected function unsetCache( string $key ) : void
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

    protected function clearCache() : void
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

    /**
     * @param string    $caller
     * @param string    $key
     * @param Throwable $exception
     *
     * @return void
     *
     * @throws LogicException
     */
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
