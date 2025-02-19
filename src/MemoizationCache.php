<?php

declare(strict_types=1);

namespace Cache;

use JetBrains\PhpStorm\Deprecated;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache as Symfony;
use Closure, LogicException;

#[Deprecated]
final class MemoizationCache extends CacheHandler
{
    private static ?MemoizationCache $instance = null;

    /**
     * @param null|CacheItemPoolInterface $cacheAdapter
     * @param null|LoggerInterface        $logger
     * @param bool                        $debug
     */
    public function __construct(
        ?CacheItemPoolInterface $cacheAdapter = null,
        ?LoggerInterface        $logger = null,
        private readonly bool   $debug = false,
    ) {
        parent::__construct( $cacheAdapter, $logger, ! $this->debug );

        if ( $this::$instance ) {
            $this->inMemoryCache = $this::$instance->inMemoryCache;
        }
        $this::$instance = $this;
    }

    /**
     * @template Type
     *
     * @param array{0:class-string, 1:string}|callable():Type|Closure():Type $callback    a function or method to cache, optionally with extra arguments as array values
     * @param ?string                                                        $key         [optional] Key - a hash based on $callback and $arguments will be used if null
     * @param ?int                                                           $persistence the duration in seconds for the cache entry
     *
     * @return Type
     * @phpstan-return Type
     */
    public function set(
        Closure|callable|array $callback,
        ?string                $key = null,
        ?int                   $persistence = EPHEMERAL,
    ) : mixed {
        if ( \is_array( $callback ) && ! \is_callable( $callback ) ) {
            [$callable, $arguments] = \array_chunk( $callback, 2 );

            \assert( \is_callable( $callable ), __METHOD__.'( $callback .. ) must be callable.' );

            $callback = static fn() => \call_user_func_array( $callable, $arguments );
        }

        \assert( $callback instanceof Closure, __METHOD__.'( $callback .. ) must be callable.' );

        $key ??= $this->keyFromCallbackArguments( $callback );

        \assert( $key, __METHOD__.'( .. $key .. ) cannot be empty.' );

        // If persistence is not requested, or if we are lacking a capable adapter
        if ( $persistence === EPHEMERAL || ! $this->cacheAdapter ) {
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
     * - To use a {@see Symfony\CacheInterface}, instantiate before making your first {@see set()} call.
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
