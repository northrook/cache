<?php

declare( strict_types = 1 );

namespace Northrook;

use Northrook\Core\Trait\SingletonClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Cache\Exception\CacheException;

/**
 * @param string  $path
 *
 * @return string
 *
 * @internal
 */
function normalizeRealPath( string $path ) : string {
    $normalize = str_replace( [ '\\', '/' ], DIRECTORY_SEPARATOR, $path );
    $exploded  = explode( DIRECTORY_SEPARATOR, $normalize );
    $path      = implode( DIRECTORY_SEPARATOR, array_filter( $exploded ) );

    return ( realpath( $path ) ?: $path ) . DIRECTORY_SEPARATOR;
}

final class CacheManager
{
    use SingletonClass;

    public const SETTINGS = [
        'ttl'          => Cache::HOUR_4,
        'ttl.memo'     => Cache::MINUTE,
        'ttl.manifest' => Cache::FOREVER,
    ];

    private static array             $cacheStatus = [];
    private readonly array           $settings;
    private readonly LoggerInterface $logger;

    /**
     * @var array<string, AdapterInterface|class-string>
     */
    private array $adapterPool = [
        'ephemeralMemoCache'  => ArrayAdapter::class,
        'persistentMemoCache' => PhpFilesAdapter::class,
    ];

    public readonly string $cacheDirectory;
    public readonly string $manifestDirectory;

    /**
     * - If you choose to provide a cache directory, the path **must** be valid and writable.
     *
     * @param ?string                         $cacheDirectory
     * @param ?string                         $manifestDirectory
     * @param array<string, int|bool|string>  $settings  Dot-notated settings
     * @param ?LoggerInterface                $logger
     */
    public function __construct(
        ?string          $cacheDirectory = null,
        ?string          $manifestDirectory = null,
        array            $settings = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->instantiationCheck();
        $this->logger   = $logger ?? new NullLogger();
        $this->settings = array_merge( CacheManager::SETTINGS, $settings );

        // Parse directories, using system temp dir if none is provided
        $cacheDirectory    ??= sys_get_temp_dir() . '/' . hash( 'xxh3', __DIR__ );
        $manifestDirectory ??= $cacheDirectory . '/' . 'manifest';

        // Set cache directories
        $this->cacheDirectory = normalizeRealPath( $cacheDirectory );;
        $this->manifestDirectory = normalizeRealPath( $manifestDirectory );

        // Start CacheManager instance
        CacheManager::$instance = $this;

        // Help the garbage collector
        unset( $cacheDirectory, $manifestDirectory, $settings, $logger );
    }

    public static function status( string $namespace, bool $regenerated = false ) : void {

        if ( $regenerated ) {
            CacheManager::$cacheStatus[ $namespace ][ 'hit' ] = -1;
            return;
        }

        CacheManager::$cacheStatus[ $namespace ][ 'hit' ] ??= 0;
        CacheManager::$cacheStatus[ $namespace ][ 'hit' ]++;
    }

    public static function get() : CacheManager {
        return CacheManager::$instance;
    }

    public static function getCacheStatus() : array {
        return CacheManager::$cacheStatus;
    }

    public function addPool(
        string           $namespace,
        AdapterInterface $adapter,
    ) : CacheManager {

        $this->adapterPool[ $namespace ] = $adapter;

        return $this;
    }

    public static function memoAdapter( ?int $ttl ) : AdapterInterface {

        $namespace = $ttl === null ? 'ephemeralMemoCache' : 'persistentMemoCache';

        return CacheManager::getAdapter( $namespace );
    }

    /**
     * @param string                               $namespace
     * @param null|class-string<AdapterInterface>  $adapter
     *
     * @return null|AdapterInterface
     */
    public static function getAdapter( string $namespace, ?string $adapter = null ) : ?AdapterInterface {

        $cache = CacheManager::getInstance();

        $adapter ??= $cache->adapterPool[ $namespace ] ?? null;

        if ( $adapter instanceof AdapterInterface ) {
            return $adapter;
        }

        return $cache->adapterPool[ $namespace ] = $cache->backupAdapter( $namespace, $adapter );
    }

    private function backupAdapter(
        string        $namespace,
        null | string $backupAdapter = null,
    ) : AdapterInterface {

        if ( $backupAdapter === ArrayAdapter::class ) {
            return new ArrayAdapter();
        }

        try {
            $adapter = $this->fallbackAdapter(
                $backupAdapter,
                $namespace,
                $this->setting( 'ttl' ),
                $this->cacheDirectory,
            );
        }
        catch ( CacheException ) {
            $adapter = new FilesystemAdapter(
                $namespace,
                $this->setting( 'ttl' ),
                $this->cacheDirectory,
            );
        }

        $this->logger->error(
            message : "Using backup cache adapter for {namespace}. Assigned {adapter} as fallback.",
            context : [
                          'namespace' => $namespace,
                          'adapter'   => $adapter::class,
                      ],
        );

        return $adapter;
    }

    /**
     * @param class-string  $className
     * @param               $arguments
     *
     * @return AdapterInterface
     * @throws CacheException
     */
    private function fallbackAdapter( string $className, ...$arguments ) : AdapterInterface {
        return new ( class_exists( $className ) ? $className : PhpFilesAdapter::class )( ...$arguments );
    }

    public function getAll() : array {

        $data = [];

        foreach ( $this->activeAdapters() as $adapter ) {
            $data[] = $adapter->getItems();
        }

        return $data;
    }

    public function purgeAll() : void {
        foreach ( $this->activeAdapters() as $adapter ) {
            $adapter->clear();
        }
    }

    private function activeAdapters() : array {
        return array_filter(
            $this->adapterPool,
            static fn ( $adapter ) : bool => $adapter instanceof AdapterInterface,
        );
    }

    /**
     * @param ?string  $get  = ... static::SETTINGS
     *
     * @return array| bool|int|string|null
     */
    private function setting(
        ?string $get,
    ) : array | bool | int | string | null {
        return $get ? $this->settings[ $get ] ?? null : $this->settings;
    }

}