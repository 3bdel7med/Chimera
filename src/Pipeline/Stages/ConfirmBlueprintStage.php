<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Pipeline\Stages;

use Abdelhmed\Chimera\Contracts\ProgressTracker;
use Abdelhmed\Chimera\Contracts\Reporter;
use Abdelhmed\Chimera\Data\FileSpec;
use Abdelhmed\Chimera\Enums\FileAction;
use Abdelhmed\Chimera\Enums\Phase;
use Abdelhmed\Chimera\Pipeline\MorphContext;
use Abdelhmed\Chimera\Pipeline\Stage;

/** Shows the plan as a file tree and asks for approval before a single token of code is generated. */
final class ConfirmBlueprintStage implements Stage
{
    public function __construct(
        private readonly Reporter $reporter,
        private readonly ProgressTracker $progress,
    ) {
    }

    public function handle(MorphContext $context): void
    {
        $blueprint = $context->blueprint;

        if ($blueprint === null) {
            $context->halt('No blueprint was produced.', true);

            return;
        }

        $this->progress->phase(Phase::Review, $blueprint->summary !== '' ? $blueprint->summary : null);
        $this->progress->plan($blueprint->files);

        if ($blueprint->hasBreakingChanges) {
            $this->reporter->warn('This plan may introduce breaking changes.');
        }

        foreach ($blueprint->warnings as $warning) {
            $this->reporter->warn($warning);
        }

        if ($context->dryRun) {
            $context->halt('Dry run: nothing was generated or written.');

            return;
        }

        $migrations = count(array_filter(
            $blueprint->files,
            fn (FileSpec $f) => $f->type->value === 'migration' && $f->action === FileAction::Create,
        ));
        $question = sprintf('Generate and write these %d files%s?', count($blueprint->files), $migrations > 0 && $context->runMigrations ? " and run {$migrations} migration(s)" : '');

        if (! $context->assumeYes && ! $this->reporter->confirm($question)) {
            $context->halt('Cancelled. Nothing was written.');

            return;
        }

        $this->progress->phaseDone(Phase::Review, 'approved');
    }
}
