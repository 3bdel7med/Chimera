<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Data;

use Abdelhmed\Chimera\Enums\FileAction;
use Abdelhmed\Chimera\Enums\FileType;

/** One entry of the blueprint: what a file is for, before any code exists. */
final class FileSpec
{
    public function __construct(
        public readonly string $path,
        public readonly FileType $type,
        public readonly FileAction $action,
        public readonly string $description = '',
    ) {
    }

    public function with(?string $path = null, ?FileAction $action = null): self
    {
        return new self($path ?? $this->path, $this->type, $action ?? $this->action, $this->description);
    }

    /** Blade templates end in .php but are not PHP source. */
    public function isPhp(): bool
    {
        return str_ends_with($this->path, '.php') && ! str_ends_with($this->path, '.blade.php');
    }
}
