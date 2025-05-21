<?php

namespace Cache\Exception;

use Psr\Cache\InvalidArgumentException;

final class InvalidCacheKeyException extends \InvalidArgumentException implements InvalidArgumentException {}
