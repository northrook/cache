<?php

declare(strict_types=1);

namespace Cache;

use Cache\LocalStorage\Item;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\VarExporter\VarExporter;
use Symfony\Component\Filesystem\Filesystem;
use Stringable, Throwable, InvalidArgumentException;
use function Support\datetime;

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
class LocalStorage extends CacheAdapter
{
    /** @var array<string, array{'value':mixed,'expiry': false|int}|Item> */
    private array $data;

    private ?string $hash;

    private readonly string $filePath;

    private readonly string $generator;

    protected bool $hasChanges = false;

    /** @var array<string, int> */
    protected array $hit = [];

    /** @var array<string, int> */
    protected array $miss = [];

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
        $this->filePath  = $filePath;
        $this->generator = $generator ?? __CLASS__;

        if ( ! $name ) {
            $name = \basename( $this->filePath );
            $name = \strrchr( $name, '.', true ) ?: $name;
        }

        $this->setName( $name );
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
            $this->log(
                '{class} {name} item {key} has expired. ',
                ['class' => $this::class, 'name' => $this->name, 'key' => $key],
            );
            $data->set( null );
        }

        // Internal hit tracker
        $this->hit[$key] = $hit ? ( $this->hit[$key] ?? 0 ) + 1 : 0;

        // Internal miss tracker
        if ( ! $hit ) {
            $this->miss[$key] = ( $this->miss[$key] ?? 0 ) + 1;
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
            $this->loadStorage()->data,
        );
    }

    public function clear() : bool
    {
        $this->data       = [];
        $this->hash       = null;
        $this->hit        = [];
        $this->miss       = [];
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
            unset(
                $this->data[(string) $key],
                $this->hit[(string) $key],
                $this->miss[(string) $key],
            );
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
        $this->loadStorage()->data[$item->getKey()] = $item;

        return true;
    }

    public function hasChanges( true $set = null ) : bool
    {
        if ( $set ) {
            $this->hasChanges = true;
        }

        return $this->hasChanges;
    }

    final public function commit( bool $force = false ) : bool
    {
        if ( $force ) {
            $this->hasChanges = true;
            $this->log(
                '{class} {name} has been forced to commit {items} items.',
                [
                    'class' => $this::class,
                    'name'  => $this->name,
                    'items' => \count( $this->data ?? [] ),
                ],
                'warning',
            );
        }

        // Do not attempt to commit anything if nothing has changed
        if ( $this->autosave && ( empty( $this->data ) || $this->hasChanges === false ) ) {
            return false;
        }

        $dataExport      = $this->exportData();
        $storageDataHash = \hash( algo : 'xxh64', data : $dataExport );

        if ( $this->validate && $storageDataHash === ( $this->hash ?? null ) ) {
            $this->log(
                '{class} {name} Matches hashes, no changes to commit.',
                ['class' => $this::class, 'name' => $this->name],
                'debug',
            );
        }
        else {
            $dateTime = datetime();

            $timestamp          = $dateTime->getTimestamp();
            $formattedTimestamp = $dateTime->format( 'Y-m-d H:i:s e' );

            $localStorage = <<<PHP
                <?php
                
                /*------------------------------------------------------%{$timestamp}%-
                
                   Name      : {$this->name}
                   Generated : {$formattedTimestamp}
                   Generator : {$this->generator}
                
                   Do not edit it manually.
                
                -#{$storageDataHash}#------------------------------------------------*/
                
                return ['{$storageDataHash}', {$dataExport}];
                PHP;

            try {
                ( new Filesystem() )->dumpFile( $this->filePath, $localStorage.PHP_EOL );
                $this->log(
                    '{class} {name} Changes committed.',
                    ['class' => $this::class, 'name' => $this->name, 'path' => $this->filePath],
                );
            }
            catch ( Throwable $exception ) {
                $this->log( $exception );
                return false;
            }
        }

        return true;
    }

    // :: Util

    /**
     * Return an array of `[0] hit` and `[1] miss` arrays.
     *
     * @return array{int[],int[]}
     */
    public function getStats() : array
    {
        return [
            $this->hit,
            $this->miss,
        ];
    }

    /**
     * @return string
     */
    protected function exportData() : string
    {
        foreach ( $this->loadStorage()->data as $key => $item ) {
            if ( $item instanceof Item ) {
                $item = [
                    'value'  => $item->get(),
                    'expiry' => $item->expiry(),
                ];
            }

            if ( $item['expiry'] > \time() ) {
                $this->log(
                    '{class} {name} item {key} has expired. ',
                    ['class' => $this::class, 'name' => $this->name, 'key' => $key],
                );
                $this->hasChanges = true;
                unset( $this->data[$key] );

                continue;
            }

            $this->data[$key] = $item;
        }

        try {
            $data = VarExporter::export( $this->data );
        }
        catch ( Throwable $e ) {
            throw new InvalidArgumentException( $e->getMessage(), $e->getCode(), $e );
        }

        return $data;
    }

    // .. Internal

    /**
     * @param string $key
     *
     * @return array{'value':mixed,'expiry': false|int}|Item
     */
    final protected function loadItemData( string $key ) : Item|array
    {
        return $this->loadStorage()->data[$key] ?? [
            'value'  => null,
            'expiry' => $this->defaultExpiry,
        ];
    }

    /**
     * @return self
     */
    final protected function loadStorage() : self
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
            \ctype_alnum( \str_replace( ['.', '-', ':'], '', $key ) ),
            $this::class." keys must only contain ASCII characters, periods, and hyphens. '".$value."' provided.",
        );

        return \strtolower( $key );
    }
}
