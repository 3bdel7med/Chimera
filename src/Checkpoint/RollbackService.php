<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Checkpoint;

use Abdelhmed\Chimera\Contracts\CheckpointStore;
use Abdelhmed\Chimera\Contracts\MigrationRunner;
use Abdelhmed\Chimera\Contracts\ProjectFiles;
use Abdelhmed\Chimera\Data\RollbackReport;
use Abdelhmed\Chimera\Exceptions\CheckpointException;
use Throwable;

/** Undoes a morph run: rolls back its migrations, restores modified files, deletes created ones. */
final class RollbackService
{
    public function __construct(
        private readonly ProjectFiles $files,
        private readonly CheckpointStore $checkpoints,
        private readonly MigrationRunner $migrations,
    ) {
    }

    public function rollback(string $id): RollbackReport
    {
        $checkpoint = $this->checkpoints->find($id) ?? throw new CheckpointException("Unknown checkpoint [{$id}].");

        if ($checkpoint->rolledBackAt !== null) {
            throw new CheckpointException("Checkpoint [{$id}] was already rolled back.");
        }

        $report = new RollbackReport();

        // Migrations go first: their files must still exist for the rollback to run.
        if ($checkpoint->migrations !== []) {
            try {
                $report->migrationOutput = $this->migrations->rollback($checkpoint->migrations);
            } catch (Throwable $e) {
                $report->warnings[] = 'Migration rollback failed: '.$e->getMessage();
            }
        }

        foreach ($checkpoint->modified as $path) {
            $original = $this->checkpoints->originalContent($id, $path);

            if ($original === null) {
                $report->warnings[] = "No backup found for {$path}; it was left as is.";

                continue;
            }

            try {
                $this->files->put($path, $original);
                $report->restored[] = $path;
            } catch (Throwable $e) {
                $report->warnings[] = "Could not restore {$path}: ".$e->getMessage();
            }
        }

        foreach ($checkpoint->created as $path) {
            try {
                $this->files->delete($path);
                $report->deleted[] = $path;
            } catch (Throwable $e) {
                $report->warnings[] = "Could not delete {$path}: ".$e->getMessage();
            }
        }

        $this->checkpoints->markRolledBack($id);

        return $report;
    }
}
