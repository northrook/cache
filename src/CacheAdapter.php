<?php

declare(strict_types=1);

namespace Cache;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\{LoggerAwareInterface, LoggerInterface, NullLogger};

abstract class CacheAdapter implements CacheItemPoolInterface, LoggerAwareInterface
{
    protected readonly LoggerInterface $logger;

    protected readonly string $name;

    /**
     * @param null|LoggerInterface $logger
     * @param bool                 $assignNull
     *
     * @return void
     */
    final public function assignLogger(
        ?LoggerInterface $logger,
        bool             $assignNull = false,
    ) : void {
        if ( $logger === null && $assignNull === false ) {
            return;
        }

        $this->logger = $logger ?? new NullLogger();
    }

    final protected function setName( ?string $name = null ) : void
    {
        if ( ! $name ) {
            $namespaced = \explode( '\\', $this::class );

            $name = \end( $namespaced ) ?: 'Cache';
        }

        $this->name = $name;
    }
}
