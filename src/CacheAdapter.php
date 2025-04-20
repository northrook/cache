<?php

declare(strict_types=1);

namespace Cache;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\{LoggerAwareInterface, LoggerInterface};
use Symfony\Component\Stopwatch\{Stopwatch, StopwatchEvent};
use function Support\{class_basename, str_start};

abstract class CacheAdapter implements CacheItemPoolInterface, LoggerAwareInterface
{
    private ?Stopwatch $stopwatch = null;

    protected ?LoggerInterface $logger = null;

    protected readonly string $name;

    final protected function setName( ?string $name = null ) : void
    {
        $this->name = $name ?? class_basename( $this::class, 'ucfirst' );
    }

    final public function setLogger( LoggerInterface $logger ) : void
    {
        $this->logger ??= $logger;
    }

    final public function setStopwatch( Stopwatch $stopwatch ) : void
    {
        $this->stopwatch ??= $stopwatch;
    }

    final protected function profile( string $name, ?string $category = 'Cache' ) : ?StopwatchEvent
    {
        $name = str_start( \trim( $name, ' .' ), 'cache.' );
        return $this->stopwatch?->start( $name, $category );
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
