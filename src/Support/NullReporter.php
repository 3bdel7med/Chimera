<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Support;

use Abdelhmed\Chimera\Contracts\Reporter;
use Closure;

/** Collects messages instead of printing them. Useful for tests and for running Chimera programmatically. */
final class NullReporter implements Reporter
{
    /** @var list<string> */
    public array $messages = [];

    public function __construct(private readonly bool $confirmAnswer = true)
    {
    }

    public function info(string $message): void
    {
        $this->messages[] = "info: {$message}";
    }

    public function success(string $message): void
    {
        $this->messages[] = "success: {$message}";
    }

    public function warn(string $message): void
    {
        $this->messages[] = "warn: {$message}";
    }

    public function error(string $message): void
    {
        $this->messages[] = "error: {$message}";
    }

    public function confirm(string $label, bool $default = true): bool
    {
        return $this->confirmAnswer;
    }

    public function spin(string $message, Closure $callback): mixed
    {
        return $callback();
    }
}
