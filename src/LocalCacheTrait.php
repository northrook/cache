<?php

declare(strict_types=1);

namespace Cache;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use LogicException;

trait LocalCacheTrait
{
    /**
     * @var array<string, string>|CacheItemPoolInterface
     */
    protected CacheItemPoolInterface|array $cache;

    /**
     * Retrieve a cached value.
     *
     * @param string $key
     *
     * @return null|string
     */
    final protected function getCache( string $key ) : ?string
    {
        if ( \is_array( $this->cache ) ) {
            return $this->cache[$key] ?? null;
        }

        try {
            if ( $this->cache->hasItem( $key ) ) {
                return $this->cache->getItem( $key )->get();
            }
        }
        catch ( Throwable $exception ) {
            $this->handleLocalCacheException( 'getCache', $key, $exception );
        }
        return null;
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
            $this->handleLocalCacheException( 'setCache', $key, $exception );
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
            $this->handleLocalCacheException( 'unsetCache', $key, $exception );
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
