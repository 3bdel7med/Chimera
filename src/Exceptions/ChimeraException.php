<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Exceptions;

use RuntimeException;

/** Base type: the console commands turn any ChimeraException into a clean error message. */
class ChimeraException extends RuntimeException
{
}
