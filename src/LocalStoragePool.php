<?php

declare(strict_types=1);

namespace Cache;

use Cache\LocalStoragePool\Item;
use Psr\Cache\{CacheItemInterface, CacheItemPoolInterface};
use Psr\Log\{LoggerAwareInterface, LoggerInterface};
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\VarExporter\VarExporter;
use Stringable, Throwable, InvalidArgumentException, LogicException;
use DateTimeImmutable, DateMalformedStringException;

/**
 * File cache designed for high-performance data persistence.
 *
 * - Data is stored as a local file, loaded lazily on demand.
 * - Write operations are deferred until the shutdown phase to minimize I/O overhead.
 * - Intended for application-generated data that can be regenerated if necessary.
 *
 *  Interoperable with:
 *  - `\Psr\SimpleCache\CacheInterface::get( $key )`
 *  - `\Symfony\Contracts\Cache\CacheInterface::get( $key, $callback )`
 *
 * @author Martin Nielsen <mn@northrook.com>
 */
final class LocalStoragePool implements CacheItemPoolInterface, LoggerAwareInterface
{
    private ?LoggerInterface $logger = null;

    /** @var array<string, array{'value':mixed,'expiry': false|int}|Item> */
    private array $data;

    private ?string $hash;

    private readonly string $filePath;

    private readonly string $name;

    private readonly string $generator;

    protected bool $hasChanges = false;

    /**
     * @param string      $filePath      Full path to the cache file
     * @param null|string $name          Derived from `$filePath` if unset
     * @param null|string $generator     LocalStoragePool if unset
     * @param bool        $autosave      [true] saves on `__destruct`
     * @param bool        $validate      [true] validate against stored hash before saving
     * @param false|int   $defaultExpiry [false] Never expires by default
     */
    public function __construct(
        string              $filePath,
        string              $name = null,
        string              $generator = null,
        protected bool      $autosave = true,
        protected bool      $validate = true,
        protected false|int $defaultExpiry = false,
    ) {
        $this->setup( $filePath, $name, $generator );
    }

    public function __destruct()
    {
        if ( $this->autosave && ! empty( $this->data ) ) {
            $this->commit();
        }
    }

    /**
     * @template Value
     *
     * @param string            $key
     * @param ?callable():Value $callback
     * @param null|mixed        $fallback
     *
     * @return mixed|Value
     */
    public function get(
        string    $key,
        ?callable $callback = null,
        mixed     $fallback = null,
    ) : mixed {
        if ( $this->hasItem( $key ) ) {
            return $this->getItem( $key )->get();
        }

        if ( \is_callable( $callback ) ) {
            $callback = $callback();
        }

        if ( ! $callback ) {
            return $fallback;
        }

        return $this->getItem( $key )->set( $callback )->get();
    }

    /**
     * @param string $key
     *
     * @return array{'value':mixed,'expiry': false|int}|Item
     */
    protected function loadItemData( string $key ) : Item|array
    {
        return $this->storage()->data[$key] ?? [
            'value'  => null,
            'expiry' => $this->defaultExpiry,
        ];
    }

    /**
     * @param string|Stringable $key
     *
     * @return Item
     */
    public function getItem( string|Stringable $key ) : Item
    {
        $key  = $this->validateKey( $key );
        $hit  = $this->hasItem( $key );
        $data = $this->loadItemData( $key );

        if ( ! $data instanceof Item ) {
            $value  = $data['value'];
            $expiry = $data['expiry'];

            $data = new Item( $key, $value, $hit, $expiry );
            $data->setPool( $this );
        }

        if ( $data->expired() ) {
            $this->logger?->info( $this->name.': Item expired: '.$key );
            $data->set( null );
        }

        return $this->data[$key] = $data;
    }

    /**
     * @param string[]|Stringable[] $keys
     *
     * @return array<string, Item>
     */
    public function getItems( array $keys = [] ) : iterable
    {
        $items = [];

        foreach ( $keys as $key ) {
            $items[(string) $key] = $this->getItem( $key );
        }
        return $items;
    }

    /**
     * @param string|Stringable $key
     *
     * @return bool
     */
    public function hasItem( string|Stringable $key ) : bool
    {
        return \array_key_exists(
            $this->validateKey( $key ),
            $this->storage()->data,
        );
    }

    public function clear() : bool
    {
        $this->data       = [];
        $this->hasChanges = true;
        return true;
    }

    /**
     * @param string|Stringable $key
     *
     * @return bool
     */
    public function deleteItem( string|Stringable $key ) : bool
    {
        if ( $this->hasItem( $key ) ) {
            unset( $this->data[(string) $key] );
            $this->hasChanges = true;
        }
        return $this->hasChanges;
    }

    /**
     * @param string[]|Stringable[] $keys
     *
     * @return bool
     */
    public function deleteItems( array $keys ) : bool
    {
        foreach ( $keys as $key ) {
            $this->deleteItem( $key );
        }
        return $this->hasChanges;
    }

    /**
     * @param CacheItemInterface $item
     *
     * @return bool
     */
    public function save( CacheItemInterface $item ) : bool
    {
        $this->saveDeferred( $item );

        return $this->commit();
    }

    public function saveDeferred( CacheItemInterface $item ) : bool
    {
        if ( ! $item instanceof Item ) {
            throw new InvalidArgumentException( 'Only '.Item::class.' instances are supported.' );
        }
        $item->setPool( $this );
        $this->storage()->data[$item->getKey()] = $item;

        return true;
    }

    public function hasChanges( bool $set = null ) : bool
    {
        if ( $set !== null ) {
            $this->hasChanges = $set;
        }

        return $this->hasChanges;
    }

    public function commit( bool $force = false ) : bool
    {
        if ( $force ) {
            $this->hasChanges = true;
        }

        // Do not attempt to commit anything if nothing has changed
        if ( $this->autosave && ( empty( $this->data ) || $this->hasChanges === false ) ) {
            $this->logger?->info(
                '{storage} has {changes} to commit.',
                [
                    'storage' => $this->name,
                    'changes' => $force ? 'been forced to' : 'changes',
                ],
            );
            return false;
        }

        $dataExport      = $this->exportData();
        $storageDataHash = \hash( algo : 'xxh3', data : $dataExport );

        if ( $this->validate && $storageDataHash === ( $this->hash ?? null ) ) {
            $this->logger?->info( $this->name.': Matches hashes, no changes to commit.' );
            return false;
        }

        $dateTime = $this->getDateTime();

        $timestamp          = $dateTime->getTimestamp();
        $formattedTimestamp = $dateTime->format( 'Y-m-d H:i:s e' );

        $localStorage = <<<PHP
            <?php
            
            /*------------------------------------------------------%{$timestamp}%-
            
               Name      : {$this->name}
               Generated : {$formattedTimestamp}
            
               This file is generated by {$this->generator}.
            
               Do not edit it manually.
            
            -#{$storageDataHash}#------------------------------------------------*/
            
            return [
                '{$storageDataHash}',
                {$dataExport}
            ];
            PHP;

        try {
            ( new Filesystem() )->dumpFile( $this->filePath, $localStorage.PHP_EOL );
            $this->logger?->info( $this->name.': Changes committed.' );
        }
        catch ( Throwable $e ) {
            throw new LogicException( $e->getMessage(), $e->getCode(), $e );
        }

        return true;
    }

    // :: Util

    protected function getDateTime() : DateTimeImmutable
    {
        // TODO: [low] static or property
        try {
            return new DateTimeImmutable( timezone : \timezone_open( 'UTC' ) ?: null );
        }
        catch ( DateMalformedStringException $e ) {
            throw new LogicException( $e->getMessage(), $e->getCode(), $e );
        }
    }

    /**
     * @return string
     */
    protected function exportData() : string
    {
        foreach ( $this->storage()->data as $key => $item ) {
            if ( $item instanceof Item ) {
                $item = [
                    'value'  => $item->get(),
                    'expiry' => $item->expiry(),
                ];
            }

            if ( $item['expiry'] > \time() ) {
                $this->logger?->info( $this->name.': Item expired: '.$key );
                $this->hasChanges = true;
                unset( $this->data[$key] );

                continue;
            }

            $this->data[$key] = $item;
        }

        try {
            return VarExporter::export( $this->data );
        }
        catch ( Throwable $e ) {
            throw new InvalidArgumentException( $e->getMessage(), $e->getCode(), $e );
        }
    }

    public function setLogger( LoggerInterface $logger ) : void
    {
        $this->logger?->warning( 'Logger already set.' );

        $this->logger ??= $logger;
    }

    /**
     * Internal logging helper.
     *
     * @internal
     *
     * @param string               $message
     * @param array<string, mixed> $context
     */
    protected function log(
        string $message,
        array  $context = [],
    ) : void {
        if ( $this->logger ) {
            $this->logger->warning( $message, $context );
        }
        else {
            $replace = [];

            foreach ( $context as $k => $v ) {
                if ( \is_scalar( $v ) ) {
                    $replace['{'.$k.'}'] = $v;
                }
            }
            @\trigger_error( \strtr( $message, $replace ), E_USER_WARNING );
        }
    }

    // .. Internal

    /**
     * @internal
     * @return self
     */
    protected function storage() : self
    {
        if ( isset( $this->data ) ) {
            return $this;
        }

        if ( ! \file_exists( $this->filePath ) ) {
            $this->data = [];
            $this->hash = 'initial';
            return $this;
        }

        try {
            [$this->hash, $this->data] = include $this->filePath;
        }
        catch ( Throwable $e ) {
            throw new InvalidArgumentException( $e->getMessage(), $e->getCode(), $e );
        }

        return $this;
    }

    /**
     * @param string|Stringable $value
     *
     * @return string
     */
    private function validateKey( string|Stringable $value ) : string
    {
        $key = (string) $value;

        \assert(
            \ctype_alnum( \str_replace( ['.', '-'], '', $key ) ),
            $this::class." keys must only contain ASCII characters, underscores and dashes. '".$value."' provided.",
        );

        return \strtolower( $key );
    }

    private function setup( string $filePath, ?string $name, ?string $generator ) : void
    {
        $this->filePath  = $filePath;
        $this->generator = $generator ?? __CLASS__;

        if ( ! $name ) {
            $name = \basename( $this->filePath );
            $name = \strrchr( $name, '.', true ) ?: $name;
        }
        $this->name = $name;
    }
}
