<?php

declare(strict_types=1);

namespace Cache;

use Core\Autowire\Logger;
use Psr\Cache\CacheItemPoolInterface;

abstract class CacheAdapter implements CacheItemPoolInterface
{
    use Logger;

    protected readonly string $name;

    final protected function setName( ?string $name = null ) : void
    {
        if ( ! $name ) {
            $namespaced = \explode( '\\', $this::class );

            $name = \end( $namespaced ) ?: 'Cache';
        }

        $this->name = $name;
    }
}
