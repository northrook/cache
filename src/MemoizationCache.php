<?php

declare(strict_types=1);

namespace Cache;

use Psr\Cache\{CacheItemPoolInterface};
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache as Symfony;
use Closure, LogicException;

final class MemoizationCache extends CacheHandler
{
    private static ?MemoizationCache $instance;

    /**
     * @param null|CacheItemPoolInterface $cacheAdapter
     * @param null|LoggerInterface        $logger
     */
    public function __construct(
        ?CacheItemPoolInterface $cacheAdapter = null,
        ?LoggerInterface        $logger = null,
    ) {
        parent::__construct( $cacheAdapter, $logger );

        if ( $this::$instance ) {
            $this->inMemoryCache = $this::$instance->inMemoryCache;
        }
        $this::$instance = $this;
    }

    /**
     * @param callable-string|Closure|string $callback
     * @param null|string                    $key
     * @param null|int                       $persistence
     *
     * @return mixed
     */
    public function cache( string|Closure $callback, ?string $key = null, ?int $persistence = EPHEMERAL ) : mixed
    {
        \assert( \is_callable( $callback ), __METHOD__.'( $callback .. ) must be callable.' );

        $key ??= $this->keyFromCallbackArguments( $callback );

        \assert( $key, __METHOD__.'( .. $key .. ) cannot be empty.' );

        // If persistence is not requested, or if we are lacking a capable adapter
        if ( EPHEMERAL === $persistence || ! $this->cacheAdapter ) {
            return $this->inMemoryCache( $key, $callback );
        }

        if ( $this->cacheAdapter instanceof Symfony\CacheInterface ) {
            return $this->symfonyCacheInterface( $key, $callback, $persistence );
        }

        return $this->psrCacheInterface( $key, $callback, $persistence );
    }

    /**
     * Retrieve the {@see MemoizationCache::$instance}, instantiating it if required.
     *
     * - To use a {@see Symfony\CacheInterface}, instantiate before making your first {@see cache()} call.
     *
     * @return MemoizationCache
     */
    public static function instance() : MemoizationCache
    {
        return MemoizationCache::$instance ?? new MemoizationCache();
    }

    /**
     * Clear the current {@see \Support\MemoizationCache::$instance}.
     *
     * ⚠️ Does _not_ reinstantiate the instance.
     *
     * @param bool $areYouSure
     *
     * @return void
     */
    public function clearStaticInstance( bool $areYouSure = false ) : void
    {
        if ( $areYouSure ) {
            $this::$instance = null;
        }

        throw new LogicException( 'Please read the '.__METHOD__.' comment before clearing the cache instance.' );
    }
}
