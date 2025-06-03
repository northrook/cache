<?php

namespace Cache\Exception;

use Core\Exception\RuntimeException;
use Psr\Cache\CacheException;

final class RuntimeCacheException extends RuntimeException implements CacheException {}
