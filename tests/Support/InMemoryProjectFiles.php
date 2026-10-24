<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Tests\Support;

use Abdelhmed\Chimera\Contracts\ProjectFiles;

class InMemoryProjectFiles implements ProjectFiles
{
    /** @param array<string, string> $files */
    public function __construct(public array $files = [])
    {
    }

    public function exists(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function get(string $path): string
    {
        return $this->files[$path];
    }

    public function put(string $path, string $content): void
    {
        $this->files[$path] = $content;
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }

    public function list(string $directory, string $extension = 'php'): array
    {
        $found = array_filter(
            array_keys($this->files),
            fn (string $p) => str_starts_with($p, rtrim($directory, '/').'/') && str_ends_with($p, '.'.$extension)
        );
        sort($found);

        return array_values($found);
    }
}
