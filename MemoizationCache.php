<?php

declare( strict_types = 1 );

namespace Northrook\Cache;

use Northrook\Core\Trait\InstantiatedStaticClass;
use Psr\Log as Psr;
use Symfony\Contracts\Cache as Symfony;
use function Northrook\{hashKey, normalizeKey};
use const Northrook\EPHEMERAL;

/**
 * Cache the result of a callable, improving  performance by avoiding redundant computations.
 *
 * It utilizes the {@see \Northrook\Cache\MemoizationCache} class,
 * which can either use an in-memory cache,  or a Symfony {@see Symfony\CacheInterface} if provided.
 *
 * @param callable  $callback     The function to cache
 * @param array     $arguments    Arguments for the $callback
 * @param ?string   $key          [optional] key - a hash based on $callback and $arguments will be used if null
 * @param ?int      $persistence  The duration in seconds for the cache entry. Requires {@see Symfony\CacheInterface}.
 *
 * @return mixed
 */
function memoize(
    callable $callback,
    array    $arguments = [],
    ?string  $key = null,
    ?int     $persistence = EPHEMERAL,
) : mixed {
    return MemoizationCache::instance()->memoize( $callback, $arguments, $key, $persistence );
}


/**
 * @author Martin Nielsen <mn@northrook.com>
 */
final class MemoizationCache
{
    use InstantiatedStaticClass;

    /**
     * - Not encrypted
     * - Not persistent
     *
     * @var array
     */
    private array $inMemoryCache = [];

    public function __construct(
        private readonly ?Symfony\CacheInterface $cacheInterface = null,
        private readonly ?Psr\LoggerInterface    $logger = null,
    ) {
        $this->instantiationCheck();
        $this::$instance = $this;
    }

    public static function instance() : MemoizationCache {
        return MemoizationCache::$instance ?? new MemoizationCache();
    }

    /**
     * Memoize a callback.
     *
     * - Can persist between requests
     * - Can be encrypted by the Adapter
     *
     * @param callable     $callback
     * @param array        $arguments
     * @param null|string  $key
     * @param null|int     $persistence
     *
     * @return mixed
     */
    public function memoize(
        callable $callback,
        array    $arguments = [],
        ?string  $key = null,
        ?int     $persistence = EPHEMERAL,
    ) : mixed {

        $key = normalizeKey( $key ?? hashKey( $arguments ) );

        if ( EPHEMERAL === $persistence || !$this->cacheInterface ) {
            if ( !isset( $this->inMemoryCache[ $key ] ) ) {
                $this->inMemoryCache[ $key ] = [
                    'value' => $callback( ...$arguments ),
                    'hit'   => 0,
                ];
            }
            else {
                $this->inMemoryCache[ $key ][ 'hit' ]++;
            }

            return $this->inMemoryCache[ $key ][ 'value' ];
        }

        try {
            return $this->cacheInterface->get(
                key      : $key,
                callback : static function ( Symfony\ItemInterface $memo ) use (
                    $callback, $arguments, $persistence,
                ) : mixed {
                    $memo->expiresAfter( $persistence );
                    return $callback( ...$arguments );
                },
            );
        }
        catch ( \Throwable $exception ) {
            $this->logger?->error(
                "Exception thrown when using {runtime}: {message}.",
                [
                    'runtime'   => $this::class,
                    'message'   => $exception->getMessage(),
                    'exception' => $exception,
                ],
            );
            return $callback( ...$arguments );
        }
    }

    public function getInMemoryCache() : array {
        return $this->inMemoryCache;
    }

    /**
     * Clears the built-in memory cache.
     *
     * @return $this
     */
    public function clearInMemoryCache() : MemoizationCache {
        $this->inMemoryCache = [];
        return $this;
    }

    /**
     * Clears the {@see CacheInterface} if assigned.
     *
     * @return $this
     */
    public function clearAdapterCache() : MemoizationCache {
        $this->cacheInterface?->clear();
        return $this;
    }
}