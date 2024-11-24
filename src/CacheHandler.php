<?php

declare(strict_types=1);

namespace Cache;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\{CacheInterface, ItemInterface};
use Closure, ReflectionFunction, Throwable, BadMethodCallException;

abstract class CacheHandler
{
    /** @var array<string, array{value: mixed, hit: int}> */
    protected array $inMemoryCache = [];

    public function __construct(
        protected readonly ?CacheItemPoolInterface $cacheAdapter = null,
        protected readonly ?LoggerInterface        $logger = null,
        private readonly bool                      $hashCacheKeys = true,
    ) {
    }

    private function cacheKey( string $value ) : string
    {
        return $this->hashCacheKeys ? \hash( 'xxh3', $value ) : $value;
    }

    /**
     * @param string           $key
     * @param callable|Closure $callback
     *
     * @return mixed
     */
    final protected function inMemoryCache(
        string           $key,
        callable|Closure $callback,
    ) : mixed {
        $key = $this->cacheKey( $key );

        if ( ! isset( $this->inMemoryCache[$key] ) ) {
            $this->inMemoryCache[$key] = [
                'value' => $callback(),
                'hit'   => 0,
            ];
        }
        else {
            $this->inMemoryCache[$key]['hit']++;
        }

        return $this->inMemoryCache[$key]['value'];
    }

    /**
     * @param string           $key
     * @param callable|Closure $callback
     * @param null|int         $persistence
     *
     * @return mixed
     */
    final protected function symfonyCacheInterface(
        string           $key,
        callable|Closure $callback,
        ?int             $persistence = EPHEMERAL,
    ) : mixed {
        \assert( $this->cacheAdapter instanceof CacheInterface );

        try {
            return $this->cacheAdapter->get(
                key      : $this->cacheKey( $key ),
                callback : static function( ItemInterface $memo ) use ( $callback, $persistence ) : mixed {
                    $memo->expiresAfter( $persistence );
                    return $callback();
                },
            );
        }
        catch ( Throwable $exception ) {
            $this->handleError( $exception );
        }
        return $callback();
    }

    /**
     * @param string           $key
     * @param callable|Closure $callback
     * @param null|int         $persistence
     *
     * @return mixed
     */
    final protected function psrCacheInterface(
        string           $key,
        callable|Closure $callback,
        ?int             $persistence = EPHEMERAL,
    ) : mixed {
        \assert( $this->cacheAdapter instanceof CacheItemPoolInterface );

        // Attempt to get the cache item
        try {
            $item = $this->cacheAdapter->getItem( $this->cacheKey( $key ) );
        }
        catch ( Throwable $exception ) {
            $this->handleError( $exception );
            return $callback();
        }

        // If the cache item is already present, return its value
        if ( $item->isHit() ) {
            return $item->get();
        }

        // Generate the value using the callback
        $value = $callback();

        // Store the value in the cache and set the persistence period
        $item->set( $value )->expiresAfter( $persistence );

        // Save the item back to the cache pool
        $this->cacheAdapter->save( $item );

        return $value;
    }

    /**
     * @return array<string, array{value: mixed, hit: int}>
     */
    final public function getInMemoryCache() : array
    {
        return $this->inMemoryCache;
    }

    /**
     * Clears the built-in memory cache.
     *
     * @return $this
     */
    final public function clearInMemoryCache() : self
    {
        $this->inMemoryCache = [];
        return $this;
    }

    /**
     * Clears the {@see CacheInterface} if assigned.
     *
     * @return $this
     */
    final public function clearAdapterCache() : self
    {
        if ( $this->cacheAdapter instanceof CacheItemPoolInterface ) {
            $this->cacheAdapter->clear();
        }
        else {
            $this->logger?->error( 'The provided cache adapter does not the clear() method.' );
        }
        return $this;
    }

    /**
     * @param callable-string|Closure $callback
     *
     * @return string
     */
    final protected function keyFromCallbackArguments( string|Closure $callback ) : string
    {
        try {
            $usedArguments = ( new ReflectionFunction( $callback ) )->getClosureUsedVariables();
            // dump( $usedArguments );
            return \serialize( $usedArguments );
        }
        catch ( Throwable $exception ) {
            throw new BadMethodCallException( $exception->getMessage(), 500, $exception );
        }
    }

    final protected function handleError( Throwable $exception ) : void
    {
        $this->logger?->error(
            'Exception thrown when using {runtime}: {message}.',
            [
                'runtime'   => $this::class,
                'message'   => $exception->getMessage(),
                'exception' => $exception,
            ],
        );
    }
}
