<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Support;

use Abdelhmed\Chimera\Exceptions\UnsafePathException;

/**
 * Every path that comes from the AI passes through here before anything is read or written.
 * Only files inside the writable top-level directories are allowed; .env, vendor, .git etc. never are.
 */
final class PathGuard
{
    /**
     * @param  list<string>  $writableRoots
     * @param  list<string>  $protectedSegments
     */
    public function __construct(
        private readonly array $writableRoots,
        private readonly array $protectedSegments = ['.git', 'vendor', 'node_modules', 'storage'],
    ) {
    }

    /** @throws UnsafePathException */
    public function normalize(string $path): string
    {
        $p = trim(str_replace('\\', '/', $path));

        if ($p === '' || str_contains($p, "\0")) {
            throw new UnsafePathException('Empty or invalid file path.');
        }

        if (str_starts_with($p, '/') || str_starts_with($p, '~') || preg_match('#^[A-Za-z]:#', $p) === 1) {
            throw new UnsafePathException("Absolute paths are not allowed: {$path}");
        }

        $p = (string) preg_replace(['#^(\./)+#', '#/+#'], ['', '/'], $p);
        $segments = explode('/', $p);
        $protected = array_map('strtolower', $this->protectedSegments);

        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.' || $segment === '') {
                throw new UnsafePathException("Path traversal is not allowed: {$path}");
            }

            if (in_array(strtolower($segment), $protected, true)) {
                throw new UnsafePathException("Protected location [{$segment}]: {$path}");
            }
        }

        if (str_starts_with(strtolower((string) end($segments)), '.env')) {
            throw new UnsafePathException("Environment files can never be written: {$path}");
        }

        $roots = array_map('strtolower', $this->writableRoots);

        if (count($segments) < 2 || ! in_array(strtolower($segments[0]), $roots, true)) {
            throw new UnsafePathException(
                "Outside the writable directories (".implode(', ', $this->writableRoots)."): {$path}"
            );
        }

        return $p;
    }

    public function isSafe(string $path): bool
    {
        try {
            $this->normalize($path);

            return true;
        } catch (UnsafePathException) {
            return false;
        }
    }
}
