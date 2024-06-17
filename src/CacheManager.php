<?php

declare( strict_types = 1 );

namespace Northrook;

use Northrook\Core\Env;
use Northrook\Core\Trait\SingletonClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Cache\Exception\CacheException;
use function Northrook\Core\Functions\normalizePath;

final class CacheManager
{
    use SingletonClass;

    public const SETTINGS = [
        'dir'                               => null,
        'ttl'                               => Cache::HOUR_4,
        'memo.ttl'                          => Cache::MINUTE,
        'memo.ephemeral.preferArrayAdapter' => null,
        'manifest.dir'                      => null,
        'manifest.ttl'                      => Cache::FOREVER,
    ];

    /**
     * @var array<string, int|bool|string>
     */
    private readonly array $settings;

    /**
     * @var array<string, AdapterInterface|class-string>
     */
    private array $adapterPool = [
        'ephemeralMemoCache'  => ArrayAdapter::class,
        'persistentMemoCache' => PhpFilesAdapter::class,
    ];

    private readonly LoggerInterface $logger;

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
        $this->logger = $logger ?? new NullLogger();

        // Parse directories, using system temp dir if none is provided
        $cacheDirectory    ??= normalizePath( sys_get_temp_dir() . '/' . hash( 'xxh3', __DIR__ ) );
        $manifestDirectory ??= normalizePath( $cacheDirectory . '/' . 'manifest' );

        $settings += CacheManager::SETTINGS;

        $settings[ 'dir' ]                               ??= $cacheDirectory;
        $settings[ 'manifest.dir' ]                      ??= $manifestDirectory;
        $settings[ 'memo.ephemeral.preferArrayAdapter' ] ??= Env::isDebug();

        $this->settings = $settings;

        // Start CacheManager instance
        CacheManager::$instance = $this;

        // Help the garbage collector
        unset( $cacheDirectory, $manifestDirectory, $settings, $logger );
    }

    public static function get() : CacheManager {
        return CacheManager::$instance;
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

        return CacheManager::getInstance()->getAdapter( $namespace );
    }

    /**
     * @param string                               $namespace
     * @param null|class-string<AdapterInterface>  $adapter
     *
     * @return null|AdapterInterface
     */
    public function getAdapter( string $namespace, ?string $adapter = null ) : ?AdapterInterface {

        $cache = CacheManager::getInstance();

        $adapter ??= $cache->adapterPool[ $namespace ] ?? null;

        if ( $adapter instanceof AdapterInterface ) {
            return $adapter;
        }

        return $cache->adapterPool[ $namespace ] = $cache->assignAdapter( $namespace, $adapter );
    }

    private function assignAdapter(
        string        $namespace,
        null | string $backupAdapter = null,
    ) : AdapterInterface {

        if ( $backupAdapter === ArrayAdapter::class ) {
            return new ArrayAdapter();
        }

        [ $ttl, $dir ] = CacheManager::setting( 'ttl', 'dir' );

        try {
            $adapter = $this->fallbackAdapter(
                $backupAdapter,
                $namespace,
                $ttl,
                $dir,
            );
        }
        catch ( CacheException ) {
            $adapter = new FilesystemAdapter(
                $namespace,
                $ttl,
                $dir,
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
     * @noinspection PhpDocRedundantThrowsInspection
     */
    private function fallbackAdapter( ?string $className, ...$arguments ) : AdapterInterface {
        return new ( class_exists( (string) $className ) ? $className : PhpFilesAdapter::class )( ...$arguments );
    }

    public function getAll() : array {

        $data = [];

        foreach ( $this->activeAdapters() as $adapter ) {
            $data[] = $adapter->getItems();
        }

        return $data;
    }

    /**
     * @param string|string[]  $pools
     * @param bool             $OPCache
     * @param bool             $realPath
     *
     * @return void
     */
    public function clear(
        string | array $pools,
        bool           $OPCache = true,
        bool           $realPath = true,
    ) : void {

        if ( $pools === 'all' ) {
            foreach ( $this->activeAdapters() as $adapter ) {
                $adapter->clear();
            }
        }

        if ( $OPCache ) {
            opcache_reset();
        }

        if ( $realPath ) {
            register_shutdown_function( 'clearstatcache' );
        }

    }

    private function activeAdapters() : array {
        return array_filter(
            $this->adapterPool,
            static fn ( $adapter ) : bool => $adapter instanceof AdapterInterface,
        );
    }

    /**
     * @param ?string  ...$get  = ['dir','ttl','memo.ephemeral.preferArrayAdapter','memo.ttl','manifest.dir','manifest.ttl'][$any]
     *
     * @return array|bool|int|string|null
     */
    public static function setting(
        ?string ...$get,
    ) : array | bool | int | string | null {

        $settings = static::getInstance()->settings;

        if ( $get === null ) {
            return $settings;
        }

        $setting = [];

        foreach ( $get as $key ) {
            $setting[] = $settings[ $key ] ?? null;
        }

        return count( $setting ) === 1 ? $setting[ key( $setting ) ] : $setting;

    }


}