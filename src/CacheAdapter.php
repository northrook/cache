<?php

namespace Cache;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\{LoggerAwareInterface, LoggerInterface};
use Symfony\Component\Stopwatch\{Stopwatch, StopwatchEvent};
use function Support\class_basename;

abstract class CacheAdapter implements CacheItemPoolInterface, LoggerAwareInterface
{
    protected readonly string $name;

    protected readonly ?LoggerInterface $logger;

    protected readonly ?Stopwatch $stopwatch;

    final protected function setName( ?string $name = null ) : void
    {
        $this->name = $name ?? class_basename( $this::class, 'ucfirst' );
    }

    final public function setLogger( LoggerInterface $logger ) : void
    {
        $this->logger?->warning( 'Logger already set.' );

        $this->logger ??= $logger;
    }

    final public function setStopwatch( Stopwatch $stopwatch ) : void
    {
        $this->logger?->warning( 'Stopwatch already set.' );

        $this->stopwatch ??= $stopwatch;
    }

    final protected function profile( string $name ) : ?StopwatchEvent
    {
        return $this->stopwatch?->start( $name, \ucfirst( $this->name ) );
    }

    /**
     * Internal logging helper.
     *
     * @internal
     *
     * @param string               $message
     * @param array<string, mixed> $context
     */
    final protected function log(
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
}
