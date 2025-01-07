<?php

declare(strict_types=1);

namespace Cache;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\VarExporter\Exception\ExceptionInterface;
use Symfony\Component\VarExporter\VarExporter;
use DateTimeImmutable;
use Throwable, LogicException, InvalidArgumentException, DateMalformedStringException;

final class LocalStorage
{
    /** @var array<string, mixed> */
    private array $data;

    private readonly string $hash;

    protected bool $locked = true;

    public readonly string $name;

    public readonly string $generator;

    public function __construct(
        private readonly string $filePath,
        ?string                 $name = null,
        ?string                 $generator = null,
        protected bool          $autosave = true,
        protected bool          $validate = true,
    ) {
        $this->name      = $name      ?? \basename( $this->filePath );
        $this->generator = $generator ?? $this::class;
    }

    public function __destruct()
    {
        if ( $this->autosave && ! empty( $this->data ) ) {
            $this->save();
        }
    }

    public function get(
        string    $key,
        ?callable $callback = null,
        mixed     $fallback = null,
    ) : mixed {
        if ( $this->has( $key ) ) {
            return $this->data[$key];
        }

        if ( \is_callable( $callback ) ) {
            $callback = $callback();
        }

        if ( ! $callback ) {
            return $fallback;
        }

        $this->set( $key, $callback );

        return $this->data[$key] ?? new LogicException();
    }

    /**
     * Set the {@see \Cache\LocalStorage::$data}.
     *
     * ⚠️ Overrides the current data.
     *
     * @param array<string, mixed> $data
     * @param bool                 $areYouSure
     *
     * @return void
     */
    public function setData( array $data, bool $areYouSure = false ) : void
    {
        if ( $areYouSure ) {
            \assert(
                // @phpstan-ignore-next-line
                empty( \array_filter( \array_keys( $data ), fn( $v ) => ! \is_string( $v ) ) ),
                $this->generator.' only accepts string keys.',
            );

            $this->data   = $data;
            $this->locked = false;
        }

        throw new LogicException( 'Please read the '.__METHOD__.' comment before replacing the data array.' );
    }

    /**
     * Merge with the current the {@see \Cache\LocalStorage::$data}.
     *
     * @param array<string, mixed> $data
     * @param bool                 $recursive
     *
     * @return void
     */
    public function addData( array $data, bool $recursive = false ) : void
    {
        \assert(
            // @phpstan-ignore-next-line
            empty( \array_filter( \array_keys( $data ), fn( $v ) => ! \is_string( $v ) ) ),
            $this->generator.' only accepts string keys.',
        );

        if ( $recursive ) {
            $this->data = \array_merge_recursive( $this->data, $data );
        }
        else {
            $this->data = \array_merge( $this->data, $data );
        }

        $this->locked = false;
    }

    /**
     * Add a `value` by `key`.
     *
     * - Will not override existing `value`.
     * - Changes won't be commited until a {@see \Cache\LocalStorage::save()} action is taken.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return void
     */
    public function add( string $key, mixed $value ) : void
    {
        if ( $this->has( $key ) ) {
            return;
        }
        $this->data[$key] = $value;
        $this->locked     = false;
    }

    /**
     * Manually set a `value` by `key`.
     *
     * - Will override current `value` if present.
     * - Changes won't be commited until a {@see \Cache\LocalStorage::save()} action is taken.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return void
     */
    public function set( string $key, mixed $value ) : void
    {
        $this->data[$key] = $value;
        $this->locked     = false;
    }

    /**
     * Check if a `key` is present in the {@see \Cache\LocalStorage::$data} set.
     *
     * @param string $key
     *
     * @return bool
     */
    public function has( string $key ) : bool
    {
        return \array_key_exists( $key, $this->getData() );
    }

    /**
     * Unsets a {@see \Cache\LocalStorage::$data} value by `key`.
     *
     * - Changes won't be commited until a {@see \Cache\LocalStorage::save()} action is taken.
     *
     * @param string $key
     *
     * @return bool
     */
    public function delete( string $key ) : bool
    {
        if ( ! isset( $this->data ) ) {
            $this->initialize();
        }

        if ( isset( $this->data[$key] ) ) {
            unset( $this->data[$key] );
            $this->locked = false;
            return true;
        }

        return false;
    }

    /**
     * Returns the keys used to store {@see \Cache\LocalStorage::$data}.
     *
     * @return string[]
     */
    public function getKeys() : array
    {
        return \array_keys( $this->getData() );
    }

    /**
     * Returns an array of all {@see \Cache\LocalStorage::$data}.
     *
     * @return array<string, mixed>
     */
    public function getAll() : array
    {
        return $this->getData();
    }

    /**
     * Commits {@see \Cache\LocalStorage::$data} to disk.
     *
     * It is recommended to perform this action after `shutdown`.
     *
     * - `autosave` is triggered on `__destruct`.
     * - {@see VarExporter} handles serialization.
     * - {@see Filesystem} handles disk operations.
     * - `locked` is ignored when `autosave` is disabled.
     * - Will check against stored `hash` before saving.
     * - Always `locked` before commiting.
     *
     * @return bool
     *
     * @throws InvalidArgumentException on `VarExporter` failures
     * @throws LogicException           on `Filesystem` or `DateTime` failures
     */
    final public function save() : bool
    {
        if ( $this->autosave && ( empty( $this->data ) || $this->locked ) ) {
            return false;
        }

        $this->locked = true;

        try {
            $dataExport = VarExporter::export( $this->data );
        }
        catch ( ExceptionInterface $e ) {
            throw new InvalidArgumentException( $e->getMessage(), $e->getCode(), $e );
        }

        $storageDataHash = \hash( algo : 'xxh3', data : $dataExport );

        if ( $this->validate && $storageDataHash === $this->hash ) {
            return false;
        }

        try {
            $dateTime = new DateTimeImmutable( timezone : \timezone_open( 'UTC' ) ?: null );
        }
        catch ( DateMalformedStringException $e ) {
            throw new LogicException( $e->getMessage(), $e->getCode(), $e );
        }

        $timestamp = $dateTime->getTimestamp();
        $date      = $dateTime->format( 'Y-m-d H:i:s e' );

        $localStorage = <<<PHP
            <?php
            
            /*--------------------------------------------------------{$timestamp}-
            
               Name      : {$this->name}
               Generated : {$date}
            
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
        }
        catch ( Throwable $e ) {
            throw new LogicException( $e->getMessage(), $e->getCode(), $e );
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function getData() : array
    {
        if ( ! isset( $this->data ) ) {
            $this->initialize();
        }

        return $this->data;
    }

    private function initialize() : void
    {
        if ( ! \file_exists( $this->filePath ) ) {
            $this->data = [];
            $this->hash = 'initial';
            return;
        }

        try {
            [$this->hash, $this->data] = include $this->filePath;
        }
        catch ( ExceptionInterface $e ) {
            throw new InvalidArgumentException( $e->getMessage(), $e->getCode(), $e );
        }
    }
}
