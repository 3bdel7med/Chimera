<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Contracts;

use Abdelhmed\Chimera\Data\FileSpec;
use Abdelhmed\Chimera\Enums\FileState;
use Abdelhmed\Chimera\Enums\Phase;

/** What the developer watches while a run is in progress: named steps and a tree of files with their state. */
interface ProgressTracker
{
    public function phase(Phase $phase, ?string $detail = null): void;

    public function phaseDone(Phase $phase, ?string $detail = null): void;

    /** A short, throw-away status line such as "analysing app/Models/User.php". */
    public function detail(string $message): void;

    /** Register the planned files and show them as a tree. @param list<FileSpec> $specs */
    public function plan(array $specs): void;

    public function file(string $path, FileState $state, ?string $note = null): void;

    /** Show the final tree with the state of every file. */
    public function summary(): void;
}
