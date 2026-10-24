<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Trust;

use Abdelhmed\Chimera\Contracts\MutationRecovery;
use Abdelhmed\Chimera\Exceptions\CheckpointException;

final class FilesystemMutationRecovery implements MutationRecovery
{
    public function __construct(private readonly string $file)
    {
    }

    public function remember(string $path, string $original): void
    {
        $this->write([$path => $original] + $this->pending());
    }

    public function forget(string $path): void
    {
        $pending = $this->pending();
        unset($pending[$path]);

        $pending === [] ? @unlink($this->file) : $this->write($pending);
    }

    public function pending(): array
    {
        if (! is_file($this->file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->file), true);

        return is_array($data) ? array_map('strval', $data) : [];
    }

    /** @param array<string, string> $pending */
    private function write(array $pending): void
    {
        $directory = dirname($this->file);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new CheckpointException("Could not create [{$directory}].");
        }

        if (file_put_contents($this->file, (string) json_encode($pending, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            throw new CheckpointException("Could not write [{$this->file}].");
        }
    }
}
