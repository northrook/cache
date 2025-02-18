<?php

declare(strict_types=1);

namespace Cache\LocalStoragePool;

use Cache\LocalStoragePool;
use Core\Interface\DataInterface;
use InvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * @template ItemValue
 * @internal
 */
final class Item implements CacheItemInterface, DataInterface
{
    private readonly LocalStoragePool $storagePool;

    /**
     * @param string         $key
     * @param null|ItemValue $value
     * @param bool           $isHit
     * @param false|int      $expiry
     */
    public function __construct(
        protected string    $key,
        protected mixed     $value = null,
        protected bool      $isHit = false,
        protected int|false $expiry = false,
    ) {}

    public function setPool( LocalStoragePool $storagePool ) : void
    {
        $this->storagePool = $storagePool;
    }

    public function getKey() : string
    {
        return $this->key;
    }

    /**
     * @return null|ItemValue
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
     * @param ItemValue $value
     *
     * @return $this
     */
    public function set( mixed $value ) : static
    {
        $this->storagePool->hasChanges( $this->value !== $value );
        $this->value = $value;

        return $this;
    }

    public function expiry() : int|false
    {
        return $this->expiry;
    }

    public function expired() : bool
    {
        return $this->expiry !== false && $this->expiry > \microtime();
    }

    /**
     * @param ?DateTimeInterface $expiration
     *
     * @return $this
     */
    public function expiresAt( ?DateTimeInterface $expiration ) : static
    {
        $this->expiry = $expiration !== null ? (int) $expiration->format( 'U.u' ) : false;

        return $this;
    }

    /**
     * @param mixed $time
     *
     * @return $this
     */
    public function expiresAfter( mixed $time ) : static
    {
        if ( ! $time ) {
            $this->expiry = false;
        }
        elseif ( \is_int( $time ) ) {
            $this->expiry = (int) ( $time + \microtime( true ) );
        }
        elseif ( $time instanceof DateInterval ) {
            $dateTime = DateTimeImmutable::createFromFormat( 'U', '0' )
                    ?: throw new InvalidArgumentException();

            $current = (int) $dateTime->add( $time )->format( 'U.u' );

            $this->expiry = (int) \microtime( true ) + $current;
        }
        else {
            throw new InvalidArgumentException(
                \sprintf(
                    'Expiration date must be an integer, a DateInterval or null, "%s" given.',
                    \get_debug_type( $time ),
                ),
            );
        }

        $this->storagePool->hasChanges( (bool) $this->expiry );

        return $this;
    }
}
