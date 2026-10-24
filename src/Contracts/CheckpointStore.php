<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Contracts;

use Abdelhmed\Chimera\Data\Checkpoint;

interface CheckpointStore
{
    /** Start a new checkpoint and return its id. */
    public function open(string $prompt): string;

    /**
     * Remember the state of a file before it is touched. The first call per path wins,
     * so later healing passes never overwrite the true original. $original is null when the file did not exist.
     */
    public function recordOriginal(string $id, string $path, ?string $original): void;

    /** @param list<string> $paths */
    public function recordMigrations(string $id, array $paths): void;

    public function originalContent(string $id, string $path): ?string;

    public function markRolledBack(string $id): void;

    public function find(string $id): ?Checkpoint;

    /** The newest checkpoint that has not been rolled back. */
    public function latest(): ?Checkpoint;

    /** @return list<Checkpoint> newest first */
    public function all(): array;
}
