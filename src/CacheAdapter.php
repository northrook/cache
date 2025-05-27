<?php

declare(strict_types=1);

namespace Cache;

use Core\Autowire\{Logger, Profiler};
use Psr\Log\{LoggerAwareInterface, LoggerInterface};
use Psr\Cache\CacheItemPoolInterface;
use function Support\{class_basename};

abstract class CacheAdapter implements CacheItemPoolInterface, LoggerAwareInterface
{
    use Logger, Profiler;

    protected readonly string $name;

    final protected function setName( ?string $name = null ) : void
    {
        $this->name = $name ?? class_basename( $this::class, 'ucfirst' );
    }

    final public function setLogger( LoggerInterface $logger ) : void
    {
        $this->logger ??= $logger;
    }
}
