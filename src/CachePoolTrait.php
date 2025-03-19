<?php

declare(strict_types=1);

namespace Cache;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Stopwatch\{Stopwatch, StopwatchEvent};
use Throwable, LogicException, InvalidArgumentException;
use function Support\str_start;
use const Support\{AUTO, CACHE_AUTO};

trait CachePoolTrait
{
    /** @var array<string, mixed>|CacheItemPoolInterface */
    protected CacheItemPoolInterface|array $cache = [];

    private readonly ?Stopwatch $cacheStopwatch;

    private readonly ?string $cacheKeyPrefix;

    protected ?int $cacheExpiration = CACHE_AUTO;

    protected bool $cacheDeferCommit = false;

    /**
     * Sets the {@see CacheItemPoolInterface}.
     *
     * - Will not override already-set Adapters
     * - Will override the in-memory array cache
     *
     * @param null|array|CacheItemPoolInterface $adapter    `PSR-6` cache adapter
     * @param null|string                       $prefix     [optional] `prefix.key`
     * @param bool                              $defer
     * @param null|int                          $expiration
     * @param null|Stopwatch                    $stopwatch
     *
     * @return void
     */
    protected function assignCacheAdapter(
        null|array|CacheItemPoolInterface $adapter,
        ?string                           $prefix = null,
        bool                              $defer = false,
        ?int                              $expiration = CACHE_AUTO,
        ?Stopwatch                        $stopwatch = null,
    ) : void {
        $this->cacheStopwatch ??= $stopwatch;

        if ( $this->cache instanceof CacheItemPoolInterface ) {
            return;
        }

        $this->cache = $adapter ?? [];
        $this->cacheExpiration ??= $expiration;
        $this->cacheDeferCommit = $defer;

        if ( $prefix ) {
            \assert(
                \ctype_alnum( \str_replace( ['.', '-'], '', $prefix ) ),
                $this::class."->cacheKeyPrefix must only contain ASCII characters, underscores and dashes. '".$prefix."' provided.",
            );

            $this->cacheKeyPrefix = \trim( $prefix, '-.' ).'.';
        }
        else {
            $this->cacheKeyPrefix = null;
        }
    }

    /**
     * @template Value
     *
     * @param ?string           $key
     * @param ?callable():Value $callback
     * @param null|mixed        $fallback
     * @param ?int              $expiration
     * @param ?bool             $defer
     *
     * @return mixed|Value
     */
    protected function getCache(
        ?string   $key,
        ?callable $callback = null,
        mixed     $fallback = null,
        ?int      $expiration = CACHE_AUTO,
        ?bool     $defer = AUTO,
    ) : mixed {
        if ( ! $key ) {
            return $fallback;
        }

        $key = $this->resolveCacheItemKey( $key );
        $this->profileCacheEvent( "get.{$key}" );

        $arrayCache = \is_array( $this->cache );

        if ( $this->hasCache( $key ) ) {
            if ( $arrayCache ) {
                return $this->cache[$key];
            }
            try {
                return $this->cache->getItem( $key )->get();
            }
            catch ( Throwable $exception ) {
                $this->handleCacheException( __METHOD__, $key, $exception );
            }
        }

        if ( ! $value = \is_callable( $callback ) ? $callback() : null ) {
            return $fallback;
        }

        if ( $arrayCache ) {
            return $this->cache[$key] = $value;
        }

        $this->setCache( $key, $value, $expiration, $defer );

        return $value;
    }

    protected function hasCache( ?string $key ) : bool
    {
        if ( ! $key ) {
            return false;
        }

        $key = $this->resolveCacheItemKey( $key );
        $this->profileCacheEvent( "has.{$key}" );

        if ( \is_array( $this->cache ) ) {
            return isset( $this->cache[$key] );
        }

        try {
            return $this->cache->hasItem( $key );
        }
        catch ( Throwable $exception ) {
            $this->handleCacheException( __METHOD__, $key, $exception );
        }

        return false;
    }

    protected function setCache(
        string $key,
        mixed  $value,
        ?int   $expiration = AUTO,
        ?bool  $defer = AUTO,
    ) : void {
        if ( ! $key ) {
            throw new InvalidArgumentException( 'Cache key must not be empty.' );
        }

        $key = $this->resolveCacheItemKey( $key );
        $this->profileCacheEvent( "set.{$key}" );

        if ( \is_array( $this->cache ) ) {
            $this->cache[$key] = $value;
            return;
        }

        try {
            $item = $this->cache->getItem( $key );
            $item
                ->expiresAfter( $expiration ?? $this->cacheExpiration )
                ->set( $value );
            if ( $defer ?? $this->cacheDeferCommit ) {
                $this->cache->saveDeferred( $item );
            }
            else {
                $this->cache->save( $item );
            }
        }
        catch ( Throwable $exception ) {
            $this->handleCacheException( __METHOD__, $key, $exception );
        }
    }

    protected function unsetCache( string $key ) : void
    {
        if ( ! $key ) {
            return;
        }

        $key = $this->resolveCacheItemKey( $key );
        $this->profileCacheEvent( "unset.{$key}" );

        if ( \is_array( $this->cache ) ) {
            unset( $this->cache[$key] );
            return;
        }

        try {
            $this->cache->deleteItem( $key );
        }
        catch ( Throwable $exception ) {
            $this->handleCacheException( __METHOD__, $key, $exception );
        }
    }

    protected function commitCache() : void
    {
        if ( \is_array( $this->cache ) ) {
            $profiler = $this->profileCacheEvent( "commit.array.{$this->cacheKeyPrefix}" );
        }
        else {
            $profiler = $this->profileCacheEvent( "commit.pool.{$this->cacheKeyPrefix}" );
            try {
                $this->cache->commit();
            }
            catch ( Throwable $exception ) {
                $this->handleCacheException( __METHOD__, 'pool', $exception );
            }
        }
        $profiler?->stop();
    }

    protected function clearCache() : void
    {
        if ( \is_array( $this->cache ) ) {
            $profiler    = $this->profileCacheEvent( "clear.array.{$this->cacheKeyPrefix}" );
            $this->cache = [];
        }
        else {
            $profiler = $this->profileCacheEvent( "clear.pool.{$this->cacheKeyPrefix}" );
            try {
                $this->cache->clear();
            }
            catch ( Throwable $exception ) {
                $this->handleCacheException( __METHOD__, 'pool', $exception );
            }
        }
        $profiler?->stop();
    }

    private function resolveCacheItemKey( string $key ) : string
    {
        if ( ! $this->cacheKeyPrefix || \str_starts_with( $key, $this->cacheKeyPrefix ) ) {
            return $key;
        }

        return $this->cacheKeyPrefix.$key;
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
    private function handleCacheException(
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

    private function profileCacheEvent( string $name ) : ?StopwatchEvent
    {
        if ( ! $this->cacheStopwatch ) {
            return null;
        }

        $name = str_start( \trim( $name, ' .' ), 'cache.' );

        if ( $this->cacheStopwatch->isStarted( $name ) ) {
            return $this->cacheStopwatch->getEvent( $name )->lap();
        }

        return $this->cacheStopwatch->start( $name, 'Cache' );
    }
}
