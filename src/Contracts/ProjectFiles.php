<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Contracts;

/** Read/write access to the host project. All paths are relative to the project root. */
interface ProjectFiles
{
    public function exists(string $path): bool;

    public function get(string $path): string;

    public function put(string $path, string $content): void;

    public function delete(string $path): void;

    /**
     * Recursively list files under a directory.
     *
     * @return list<string> sorted relative paths
     */
    public function list(string $directory, string $extension = 'php'): array;
}
