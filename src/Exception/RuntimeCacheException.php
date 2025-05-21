<?php

namespace Cache\Exception;

use Psr\Cache\CacheException;
use RuntimeException;

final class RuntimeCacheException extends RuntimeException implements CacheException {}
