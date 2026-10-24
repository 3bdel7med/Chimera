<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Pipeline\Stages;

use Abdelhmed\Chimera\Contracts\MutationRecovery;
use Abdelhmed\Chimera\Contracts\ProjectFiles;
use Abdelhmed\Chimera\Contracts\Reporter;
use Abdelhmed\Chimera\Pipeline\MorphContext;
use Abdelhmed\Chimera\Pipeline\Stage;

/** If a previous run was killed while a file held a deliberate bug, put the original back before doing anything else. */
final class RecoverInterruptedMutationsStage implements Stage
{
    public function __construct(
        private readonly MutationRecovery $recovery,
        private readonly ProjectFiles $files,
        private readonly Reporter $reporter,
    ) {
    }

    public function handle(MorphContext $context): void
    {
        foreach ($this->recovery->pending() as $path => $original) {
            $this->files->put($path, $original);
            $this->recovery->forget($path);
            $this->reporter->warn("Restored {$path}: a previous Chimera run was interrupted while stress-testing it.");
        }
    }
}
