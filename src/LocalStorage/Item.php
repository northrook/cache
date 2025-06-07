<?php

declare(strict_types=1);

namespace Cache\LocalStorage;

use Cache\LocalStorage;
use Psr\Cache\CacheItemInterface;
use DateInterval,
DateTimeImmutable,
InvalidArgumentException,
DateTimeInterface;

/**
 * @internal
 */
final class Item implements CacheItemInterface
{
    private readonly LocalStorage $storagePool;

    /**
     * @param string    $key
     * @param mixed     $value
     * @param bool      $isHit
     * @param false|int $expiry
     */
    public function __construct(
        protected string    $key,
        protected mixed     $value = null,
        protected bool      $isHit = false,
        protected int|false $expiry = false,
    ) {}

    /**
     * @internal
     *
     * @param LocalStorage $storagePool
     */
    public function setPool( LocalStorage $storagePool ) : void
    {
        $this->storagePool ??= $storagePool;
    }

    public function getKey() : string
    {
        return $this->key;
    }

    /**
     * @return mixed
     */
    public function get() : mixed
    {
        return $this->value;
    }

    public function isHit() : bool
    {
        return $this->isHit;
    }

    /**
     * @param mixed $value
     *
     * @return $this
     */
    public function set( mixed $value ) : static
    {
        $this->storagePool->hasChanges( true );
        $this->value = $value;

        return $this;
    }

    /**
     * @internal
     * @return false|int
     */
    public function expiry() : int|false
    {
        return $this->expiry;
    }

    /**
     * @internal
     * @return bool
     */
    public function expired() : bool
    {
        return $this->expiry && ( $this->expiry < \time() );
    }

    /**
     * @param ?DateTimeInterface $expiration
     *
     * @return $this
     */
    public function expiresAt( ?DateTimeInterface $expiration ) : static
    {
        $this->expiry = $expiration ? (int) $expiration->format( 'U' ) : false;

        return $this;
    }

    /**
     * @param null|DateInterval|int $time
     *
     * @return $this
     */
    public function expiresAfter( int|DateInterval|null $time ) : static
    {
        if ( $time instanceof DateInterval ) {
            $dateTime = DateTimeImmutable::createFromFormat( 'U', '0' )
                    ?: throw new InvalidArgumentException();

            $time = (int) $dateTime->add( $time )->format( 'U' );
        }

        $this->expiry = ! $time ? false : $time + \time();

        $this->storagePool->hasChanges( $this->expiry ? true : null );

        return $this;
    }
}
