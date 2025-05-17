<?php

declare(strict_types=1);

namespace Cache;

use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Core\Interface\{LogHandler, Loggable};
use Symfony\Component\Stopwatch\{Stopwatch, StopwatchEvent};
use function Support\{class_basename, str_start};

abstract class CacheAdapter implements CacheItemPoolInterface, Loggable
{
    use LogHandler;

    private ?Stopwatch $stopwatch = null;

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
        if ( ! $this->stopwatch ) {
            return null;
        }
        $name = str_start( \trim( $name, ' .' ), 'cache.' );
        return $this->stopwatch->start( $name, $category );
    }
}
