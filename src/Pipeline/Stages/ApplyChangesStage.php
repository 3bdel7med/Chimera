<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Pipeline\Stages;

use Abdelhmed\Chimera\Application\ChangeApplier;
use Abdelhmed\Chimera\Checkpoint\RollbackService;
use Abdelhmed\Chimera\Contracts\CheckpointStore;
use Abdelhmed\Chimera\Contracts\ProgressTracker;
use Abdelhmed\Chimera\Contracts\Reporter;
use Abdelhmed\Chimera\Enums\FileState;
use Abdelhmed\Chimera\Enums\Phase;
use Abdelhmed\Chimera\Pipeline\MorphContext;
use Abdelhmed\Chimera\Pipeline\Stage;
use Throwable;

/** Opens a checkpoint, then writes and injects every generated file. A failure part-way restores everything. */
final class ApplyChangesStage implements Stage
{
    public function __construct(
        private readonly ChangeApplier $applier,
        private readonly CheckpointStore $checkpoints,
        private readonly RollbackService $rollback,
        private readonly Reporter $reporter,
        private readonly ProgressTracker $progress,
    ) {
    }

    public function handle(MorphContext $context): void
    {
        $this->progress->phase(Phase::Apply, 'backing up originals, then writing files');

        $id = $this->checkpoints->open($context->prompt);
        $context->checkpointId = $id;

        try {
            $report = $this->applier->apply($id, $context->generated);
        } catch (Throwable $e) {
            $this->reporter->error('Applying the changes failed, restoring your project...');
            $this->rollback->rollback($id);

            throw $e;
        }

        $context->report = $report;

        foreach ($report->created as $path) {
            $this->progress->file($path, FileState::Written, 'created');
        }
        foreach ($report->modified as $path) {
            $this->progress->file($path, FileState::Written, 'modified');
        }
        foreach ($report->injected as $path) {
            $this->progress->file($path, FileState::Written, 'injected (AST)');
        }
        foreach ($report->skipped as $path => $reason) {
            $this->progress->file($path, FileState::Unchanged, $reason);
        }

        $this->progress->phaseDone(Phase::Apply, sprintf(
            '%d created, %d modified, %d injected',
            count($report->created),
            count($report->modified),
            count($report->injected),
        ));
    }
}
