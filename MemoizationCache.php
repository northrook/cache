<?php

namespace Northrook\Cache;

use Northrook\Core\Trait\InstantiatedStaticClass;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use function Northrook\hashKey;
use function Northrook\normalizeKey;

const EPHEMERAL = -1;
const AUTO      = null;
const FOREVER   = 0;
const MINUTE    = 60;
const HOUR      = 3600;
const HOUR_4    = 14400;
const HOUR_8    = 28800;
const HOUR_12   = 43200;
const DAY       = 86400;
const WEEK      = 604800;
const MONTH     = 2592000;
const YEAR      = 31536000;

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

    // Now found in functions, as const\Northrook\%CONST%
    public const EPHEMERAL = -1;
    public const AUTO      = null;
    public const FOREVER   = 0;
    public const MINUTE    = 60;
    public const HOUR      = 3600;
    public const HOUR_4    = 14400;
    public const HOUR_8    = 28800;
    public const HOUR_12   = 43200;
    public const DAY       = 86400;
    public const WEEK      = 604800;
    public const MONTH     = 2592000;
    public const YEAR      = 31536000;

    /**
     * - Not encrypted
     * - Not persistent
     *
     * @var array
     */
    private array $inMemoryCache = [];

    public function __construct(
        private readonly ?CacheInterface  $cacheInterface = null,
        private readonly ?LoggerInterface $logger = null,
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
                callback : static function ( ItemInterface $memo ) use (
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