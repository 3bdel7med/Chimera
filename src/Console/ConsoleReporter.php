<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Console;

use Abdelhmed\Chimera\Contracts\Reporter;
use Closure;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

/** Reporter backed by Laravel Prompts. */
final class ConsoleReporter implements Reporter
{
    public function info(string $message): void
    {
        info($message);
    }

    public function success(string $message): void
    {
        info('✔ '.$message);
    }

    public function warn(string $message): void
    {
        warning($message);
    }

    public function error(string $message): void
    {
        error($message);
    }

    public function confirm(string $label, bool $default = true): bool
    {
        return confirm($label, $default);
    }

    public function spin(string $message, Closure $callback): mixed
    {
        return spin($callback, $message);
    }
}
