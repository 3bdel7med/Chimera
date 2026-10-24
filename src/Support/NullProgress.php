<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Support;

use Abdelhmed\Chimera\Contracts\ProgressTracker;
use Abdelhmed\Chimera\Enums\FileState;
use Abdelhmed\Chimera\Enums\Phase;

/** Shows nothing. Use it when running Chimera programmatically. */
final class NullProgress implements ProgressTracker
{
    public function phase(Phase $phase, ?string $detail = null): void
    {
    }

    public function phaseDone(Phase $phase, ?string $detail = null): void
    {
    }

    public function detail(string $message): void
    {
    }

    public function plan(array $specs): void
    {
    }

    public function file(string $path, FileState $state, ?string $note = null): void
    {
    }

    public function summary(): void
    {
    }
}
