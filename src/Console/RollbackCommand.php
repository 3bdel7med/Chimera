<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Console;

use Abdelhmed\Chimera\Checkpoint\RollbackService;
use Abdelhmed\Chimera\Contracts\CheckpointStore;
use Abdelhmed\Chimera\Exceptions\ChimeraException;
use Illuminate\Console\Command;

class RollbackCommand extends Command
{
    protected $signature = 'chimera:rollback
        {id? : Checkpoint id (defaults to the latest one)}
        {--yes : Skip the confirmation prompt}';

    protected $description = 'Undo a Chimera run: roll back its migrations and restore or delete its files';

    public function handle(CheckpointStore $checkpoints, RollbackService $rollback): int
    {
        $checkpoint = $this->argument('id') ? $checkpoints->find((string) $this->argument('id')) : $checkpoints->latest();

        if ($checkpoint === null) {
            $this->components->error('No matching checkpoint found. Run chimera:history to list them.');

            return self::FAILURE;
        }

        $this->components->info("Checkpoint {$checkpoint->id}: \"{$checkpoint->prompt}\"");
        $this->line(sprintf(
            '  %d file(s) will be deleted, %d restored, %d migration(s) rolled back.',
            count($checkpoint->created),
            count($checkpoint->modified),
            count($checkpoint->migrations),
        ));

        if (! $this->option('yes') && ! $this->confirm('Roll back this run?', true)) {
            return self::SUCCESS;
        }

        try {
            $report = $rollback->rollback($checkpoint->id);
        } catch (ChimeraException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($report->warnings as $warning) {
            $this->components->warn($warning);
        }

        $this->components->info(sprintf('Rolled back: %d restored, %d deleted.', count($report->restored), count($report->deleted)));

        return self::SUCCESS;
    }
}
