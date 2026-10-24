<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Contracts;

/**
 * Trust scoring edits your source files temporarily. This keeps their original content on disk until they are restored,
 * so a killed process can never leave a deliberately broken file behind.
 */
interface MutationRecovery
{
    public function remember(string $path, string $original): void;

    public function forget(string $path): void;

    /** @return array<string, string> path => original content of files that were never restored */
    public function pending(): array;
}
