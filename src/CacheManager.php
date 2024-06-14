<?php

namespace Northrook;

use Northrook\Cache\Internal\Timestamp;
use Northrook\Cache\Persistence;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Cache\Exception\CacheException;

final class CacheManager
{

    private static CacheManager      $instance;
    private static array             $cacheStatus = [];
    private readonly bool            $usingSystemCacheDirectory;
    private readonly LoggerInterface $logger;
    /**
     * @var array<string, int|bool|string>
     */
    private readonly array $settings;
    /**
     * @var array<string, AdapterInterface|class-string>
     */
    private array          $adapterPool = [
        'ephemeralMemoCache'  => ArrayAdapter::class,
        'persistentMemoCache' => PhpFilesAdapter::class,
    ];
    public readonly string $cacheDirectory;

    /**
     * - If you choose to provide a cache directory, the path **must** be valid and writable.
     *
     *
     * @param null|string           $cacheDirectory
     * @param null|string           $subDirectory
     * @param array                 $settings  Dot-notated settings
     * @param null|LoggerInterface  $logger
     */
    public function __construct(
        ?string          $cacheDirectory = null,
        ?string          $subDirectory = null,
        array            $settings = [],
        ?LoggerInterface $logger = null,
    ) {
        if ( isset( CacheManager::$instance ) ) {
            throw new \LogicException(
                'The ' . CacheManager::class . ' has already been instantiated. 
                It cannot be re-instantiated.',
            );
        }

        new Cache( $settings );

        $this->cacheDirectory = $this->setCacheDirectory( $cacheDirectory, $subDirectory );

        // TODO : Integrate Northrook/Logger as fallback
        $this->logger = $logger ?? new \Psr\Log\NullLogger();

        CacheManager::$instance = $this;
    }

    public static function status( string $namespace, bool $regenerated = false ) : void {

        if ( $regenerated ) {
            CacheManager::$cacheStatus[ $namespace ][ 'hit' ] = -1;
            return;
        }

        CacheManager::$cacheStatus[ $namespace ][ 'hit' ] ??= 0;
        CacheManager::$cacheStatus[ $namespace ][ 'hit' ]++;
    }

    public static function getCacheStatus() : array {
        return CacheManager::$cacheStatus;
    }

    public static function memoAdapter( ?int $ttl, ?string $cacheKey = null ) : AdapterInterface {

        $namespace = $ttl === null ? 'ephemeralMemoCache' : 'persistentMemoCache';

        return CacheManager::getAdapter( $namespace );
    }

    private static function getInstance() : CacheManager {
        return CacheManager::$instance;
    }

    public static function getAdapter( string $namespace ) : ?AdapterInterface {

        $cache = CacheManager::getInstance();

        $adapter = $cache->adapterPool[ $namespace ] ?? null;

        if ( $adapter instanceof AdapterInterface ) {
            return $adapter;
        }

        return $cache->adapterPool[ $namespace ] = $cache->backupAdapter( $namespace, $adapter );
    }

    public function addPool(
        string           $namespace,
        AdapterInterface $adapter,
    ) : CacheManager {

        $this->adapterPool[ $namespace ] = $adapter;

        return $this;
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

    private function backupAdapter( string $namespace, ?string $backupAdapter = null ) : AdapterInterface {

        if ( $backupAdapter === ArrayAdapter::class ) {
            return new ArrayAdapter();
        }

        if ( !$backupAdapter || !class_exists( $backupAdapter ) ) {
            $backupAdapter = PhpFilesAdapter::class;
        }

        try {
            $adapter = new $backupAdapter(
                $namespace,
                Cache::setting( 'ttl' ),
                $this->cacheDirectory,
            );
        }
        catch ( CacheException $e ) {
            $adapter = new FilesystemAdapter(
                $namespace,
                Cache::setting( 'ttl' ),
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
     * Assign a cache directory for the cache manager.
     *
     * - Prefers using the provided $cacheDirectory
     * - Will fall back to the system temp directory.
     * - If the $systemCacheDirectory is used, a $subDirectory is required
     *   - If no $subDirectory is provided, a hash of the __DIR__ is used
     * - A $subDirectory will be created in the provided $cacheDirectory
     *
     * @param null|string  $cacheDirectory
     * @param null|string  $subDirectory
     *
     * @return string
     */
    private function setCacheDirectory(
        ?string $cacheDirectory = null,
        ?string $subDirectory = null,
    ) : string {

        $this->usingSystemCacheDirectory = $cacheDirectory === null;

        $cacheDirectory = $cacheDirectory ?? sys_get_temp_dir();

        if ( $this->usingSystemCacheDirectory ) {
            $subDirectory ??= hash( 'xxh3', __DIR__ );
        }

        if ( $subDirectory ) {
            $cacheDirectory .= DIRECTORY_SEPARATOR . $subDirectory;
        }

        return realpath( $cacheDirectory ) ?: $cacheDirectory;
    }

}