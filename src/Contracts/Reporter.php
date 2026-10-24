<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Contracts;

use Closure;

/** Everything the pipeline says to or asks of the developer. */
interface Reporter
{
    public function info(string $message): void;

    public function success(string $message): void;

    public function warn(string $message): void;

    public function error(string $message): void;

    public function confirm(string $label, bool $default = true): bool;

    /** Run $callback while showing $message as progress, and return its result. */
    public function spin(string $message, Closure $callback): mixed;
}
