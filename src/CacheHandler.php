<?php

declare(strict_types=1);

namespace Cache;

use Core\Exception\ErrorException;
use Core\Interface\ProfilerInterface;
use Core\Profiler;
use Cache\Exception\{InvalidCacheKeyException, RuntimeCacheException};
use LogicException;
use Psr\Cache\{CacheItemInterface, CacheItemPoolInterface};
use Psr\Log\{LoggerAwareInterface, LoggerInterface};
use Symfony\Component\Stopwatch\Stopwatch;
use Throwable;

final class CacheHandler implements LoggerAwareInterface
{
    private readonly ?string $cacheKeyPrefix;

    /** @var ?CacheItemPoolInterface */
    private readonly ?CacheItemPoolInterface $cacheAdapter;

    private readonly ProfilerInterface $profiler;

    /** @var array<string, mixed> */
    protected array $inMemory;

    /**
     * @param null|array<string, mixed>|CacheItemPoolInterface $adapter
     * @param null|string                                      $prefix
     * @param null|int                                         $expiration
     * @param bool                                             $deferCommit
     * @param ?LoggerInterface                                 $logger
     * @param null|bool|ProfilerInterface|Stopwatch            $profiler
     */
    public function __construct(
        null|array|CacheItemPoolInterface     $adapter,
        ?string                               $prefix = null,
        protected ?int                        $expiration = null,
        protected bool                        $deferCommit = false,
        private ?LoggerInterface              $logger = null,
        null|bool|Stopwatch|ProfilerInterface $profiler = null,
    ) {
        $adapter ??= [];

        if ( $adapter instanceof CacheItemPoolInterface ) {
            $this->cacheAdapter = $adapter;
            $this->inMemory     = [];
        }
        else {
            $this->cacheAdapter = null;
            $this->inMemory     = $adapter;
        }

        if ( $prefix ) {
            \assert(
                \ctype_alnum( \str_replace( ['.', '-'], '', $prefix ) ),
                "Cache '\$prefix' must only contain ASCII characters, hyphens, and periods. '".$prefix."' provided.",
            );

            $this->cacheKeyPrefix = \trim( $prefix, '-.' ).'.';
        }
        else {
            $this->cacheKeyPrefix = null;
        }

        $this->profiler = Profiler::from(
            profiler : $profiler,
            category : $this->cacheKeyPrefix ?? 'CacheHandler',
        );
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
    public function get(
        ?string   $key,
        ?callable $callback = null,
        mixed     $fallback = null,
        ?int      $expiration = null,
        ?bool     $defer = null,
    ) : mixed {
        if ( ! $key ) {
            return $fallback;
        }

        $key = $this->resolveCacheItemKey( $key );

        $this->profiler->start( "get.{$key}" );

        $inMemory = $this->cacheAdapter === null;

        $value = $fallback;

        if ( $this->has( $key ) ) {
            try {
                $value = $inMemory
                        ? $this->inMemory[$key]
                        : $this->cacheAdapter?->getItem( $key )->get();
            }
            catch ( Throwable $exception ) {
                $this->handleCacheException( __METHOD__, $key, $exception );
            }
        }
        else {
            $value = \is_callable( $callback ) ? $callback() : $fallback;
        }

        if ( $inMemory ) {
            $this->inMemory[$key] = $value;
        }
        else {
            $this->set( $key, $value, $expiration, $defer );
        }

        $this->profiler->stop( "get.{$key}" );
        return $value;
    }

    public function has( ?string $key ) : bool
    {
        if ( ! $key ) {
            return false;
        }

        $key = $this->resolveCacheItemKey( $key );

        if ( $this->cacheAdapter === null ) {
            return isset( $this->inMemory[$key] );
        }

        try {
            return $this->cacheAdapter->hasItem( $key );
        }
        catch ( Throwable $exception ) {
            $this->handleCacheException( __METHOD__, $key, $exception );
        }

        return false;
    }

    public function set(
        string $key,
        mixed  $value,
        ?int   $expiration = null,
        ?bool  $defer = null,
    ) : void {
        if ( ! $key ) {
            throw new InvalidCacheKeyException( 'Cache key must not be empty.' );
        }

        $key = $this->resolveCacheItemKey( $key );

        $this->profiler->start( "set.{$key}" );

        if ( $this->cacheAdapter === null ) {
            $this->inMemory[$key] = $value;
            return;
        }

        try {
            $item = $this->cacheAdapter->getItem( $key );
            $item
                ->expiresAfter( $expiration ?? $this->expiration )
                ->set( $value );
            if ( $defer ?? $this->deferCommit ) {
                $this->cacheAdapter->saveDeferred( $item );
            }
            else {
                $this->cacheAdapter->save( $item );
                $this->profiler->lap( "set.{$key}" );
            }
        }
        catch ( Throwable $exception ) {
            $this->handleCacheException( __METHOD__, $key, $exception );
        }

        $this->profiler->stop( "set.{$key}" );
    }

    public function delete( string $key ) : void
    {
        $key = $this->resolveCacheItemKey( $key );

        unset( $this->inMemory[$key] );

        try {
            $this->cacheAdapter?->deleteItem( $key );
        }
        catch ( Throwable $exception ) {
            $this->handleCacheException( __METHOD__, $key, $exception );
        }
    }

    // ..

    public function commit() : void
    {
        if ( ! $this->cacheAdapter ) {
            return;
        }

        foreach ( $this->inMemory as $key => $value ) {
            if ( ! $this->has( $key ) ) {
                $this->set( $key, $value );
            }
        }
        try {
            $this->cacheAdapter->commit();
        }
        catch ( Throwable $exception ) {
            $this->handleCacheException( __METHOD__, 'commit.pool', $exception );
        }
    }

    public function clearCache() : void
    {
        $this->inMemory = [];

        try {
            $this->cacheAdapter?->clear();
        }
        catch ( Throwable $exception ) {
            $this->handleCacheException( __METHOD__, 'clear.adapter', $exception );
        }
    }

    // ::

    /**
     * Retrieve the current `PSR\Cache` adapter if set.
     *
     * @return null|CacheItemPoolInterface
     */
    public function getCacheAdapter() : ?CacheItemPoolInterface
    {
        return $this->cacheAdapter;
    }

    public function getItem( string $key ) : ?CacheItemInterface
    {
        $key = $this->resolveCacheItemKey( $key );

        try {
            return $this->cacheAdapter?->getItem( $key );
        }
        catch ( Throwable $exception ) {
            $this->handleCacheException( __METHOD__, $key, $exception );
        }
        return null;
    }

    public function deferCommit( ?bool $set = null ) : bool
    {
        if ( $set !== null ) {
            $this->deferCommit = $set;
        }

        return $this->deferCommit;
    }

    public function expiration() : ?int
    {
        return $this->expiration;
    }

    public function setExpiration(
        null|int|string $expiration,
        bool            $silenceError = false,
    ) : void {
        if ( \is_string( $expiration ) ) {
            $expiration = \strtotime( $expiration ) ?: null;

            if ( $silenceError === false ) {
                ErrorException::check();
            }
        }

        $this->expiration = $expiration;
    }

    public function setLogger( ?LoggerInterface $logger ) : void
    {
        $this->logger = $logger;
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
        // Use PSR Logger if available
        if ( $this->logger instanceof LoggerInterface ) {
            $this->logger->error(
                '{caller}: {key}. '.$exception->getMessage(),
                [
                    'caller'    => $caller,
                    'key'       => $key,
                    'exception' => $exception,
                ],
            );
            return;
        }

        // Throw as a last resort
        throw new RuntimeCacheException(
            "{$caller}: {$key}. ".$exception->getMessage(),
            $exception->getCode(),
            $exception,
        );
    }
}
