<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Filesystem;

use Abdelhmed\Chimera\Contracts\ProjectFiles;
use Abdelhmed\Chimera\Exceptions\ChimeraException;
use Abdelhmed\Chimera\Exceptions\UnsafePathException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class LocalProjectFiles implements ProjectFiles
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath), '/');
    }

    public function exists(string $path): bool
    {
        return is_file($this->absolute($path));
    }

    public function get(string $path): string
    {
        $content = @file_get_contents($this->absolute($path));

        if ($content === false) {
            throw new ChimeraException("Could not read [{$path}].");
        }

        return $content;
    }

    public function put(string $path, string $content): void
    {
        $absolute = $this->absolute($path);
        $directory = dirname($absolute);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new ChimeraException("Could not create directory for [{$path}].");
        }

        if (file_put_contents($absolute, $content, LOCK_EX) === false) {
            throw new ChimeraException("Could not write [{$path}].");
        }
    }

    public function delete(string $path): void
    {
        $absolute = $this->absolute($path);

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    public function list(string $directory, string $extension = 'php'): array
    {
        $root = $this->absolute($directory);

        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.'.$extension)) {
                $files[] = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($this->basePath)), '/');
            }
        }

        sort($files);

        return $files;
    }

    private function absolute(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            throw new UnsafePathException("Path escapes the project root: {$path}");
        }

        return $this->basePath.'/'.$path;
    }
}
