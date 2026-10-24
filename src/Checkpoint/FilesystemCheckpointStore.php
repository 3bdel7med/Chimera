<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Checkpoint;

use Abdelhmed\Chimera\Contracts\CheckpointStore;
use Abdelhmed\Chimera\Data\Checkpoint;
use Abdelhmed\Chimera\Exceptions\CheckpointException;

/** Stores each checkpoint as a folder: manifest.json plus a copy of every file's original content. */
final class FilesystemCheckpointStore implements CheckpointStore
{
    private readonly string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim(str_replace('\\', '/', $directory), '/');
    }

    public function open(string $prompt): string
    {
        $id = date('Ymd_His').'_'.bin2hex(random_bytes(2));

        $this->write($id, (new Checkpoint($id, $prompt, date('c')))->toArray());

        return $id;
    }

    public function recordOriginal(string $id, string $path, ?string $original): void
    {
        $manifest = $this->read($id);

        if (in_array($path, $manifest['created'], true) || in_array($path, $manifest['modified'], true)) {
            return;
        }

        if ($original === null) {
            $manifest['created'][] = $path;
        } else {
            $this->put($this->backupPath($id, $path), $original);
            $manifest['modified'][] = $path;
        }

        $this->write($id, $manifest);
    }

    public function recordMigrations(string $id, array $paths): void
    {
        $manifest = $this->read($id);
        $manifest['migrations'] = array_values(array_unique([...$manifest['migrations'], ...$paths]));
        $this->write($id, $manifest);
    }

    public function originalContent(string $id, string $path): ?string
    {
        $file = $this->backupPath($id, $path);

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    public function markRolledBack(string $id): void
    {
        $manifest = $this->read($id);
        $manifest['rolled_back_at'] = date('c');
        $this->write($id, $manifest);
    }

    public function find(string $id): ?Checkpoint
    {
        return is_file($this->manifestPath($id)) ? Checkpoint::fromArray($this->read($id)) : null;
    }

    public function latest(): ?Checkpoint
    {
        foreach ($this->all() as $checkpoint) {
            if ($checkpoint->rolledBackAt === null) {
                return $checkpoint;
            }
        }

        return null;
    }

    public function all(): array
    {
        $checkpoints = [];

        foreach (glob($this->directory.'/*/manifest.json') ?: [] as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true);

            if (is_array($data) && isset($data['id'])) {
                $checkpoints[] = Checkpoint::fromArray($data);
            }
        }

        usort($checkpoints, fn (Checkpoint $a, Checkpoint $b) => strcmp($b->id, $a->id));

        return $checkpoints;
    }

    /** @return array{id: string, created: list<string>, modified: list<string>, migrations: list<string>}&array<string, mixed> */
    private function read(string $id): array
    {
        $file = $this->manifestPath($id);

        if (! is_file($file)) {
            throw new CheckpointException("Unknown checkpoint [{$id}].");
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data)) {
            throw new CheckpointException("Checkpoint [{$id}] is corrupted.");
        }

        return $data + ['created' => [], 'modified' => [], 'migrations' => []];
    }

    /** @param array<string, mixed> $manifest */
    private function write(string $id, array $manifest): void
    {
        $this->put($this->manifestPath($id), (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function put(string $file, string $content): void
    {
        $directory = dirname($file);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new CheckpointException("Could not create [{$directory}].");
        }

        if (file_put_contents($file, $content, LOCK_EX) === false) {
            throw new CheckpointException("Could not write [{$file}].");
        }
    }

    private function manifestPath(string $id): string
    {
        return $this->safeId($id).'/manifest.json';
    }

    private function backupPath(string $id, string $path): string
    {
        if (str_contains($path, '..')) {
            throw new CheckpointException("Invalid path [{$path}].");
        }

        return $this->safeId($id).'/files/'.ltrim($path, '/');
    }

    private function safeId(string $id): string
    {
        if (preg_match('/^[A-Za-z0-9_\-]+$/', $id) !== 1) {
            throw new CheckpointException("Invalid checkpoint id [{$id}].");
        }

        return $this->directory.'/'.$id;
    }
}
