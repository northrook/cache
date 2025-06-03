<?php

namespace Cache\Exception;

use Core\Exception\RuntimeException;
use Psr\Cache\InvalidArgumentException;

final class InvalidCacheKeyException extends RuntimeException implements InvalidArgumentException {}
