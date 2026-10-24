<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Pipeline\Stages;

use Abdelhmed\Chimera\Contracts\ProgressTracker;
use Abdelhmed\Chimera\Contracts\Reporter;
use Abdelhmed\Chimera\Data\FileSpec;
use Abdelhmed\Chimera\Enums\FileState;
use Abdelhmed\Chimera\Enums\Phase;
use Abdelhmed\Chimera\Generation\FileGenerator;
use Abdelhmed\Chimera\Pipeline\MorphContext;
use Abdelhmed\Chimera\Pipeline\Stage;
use Abdelhmed\Chimera\Planning\BatchPlanner;

/** Phase 2: generate the code, one batch of files per AI call. */
final class GenerateFilesStage implements Stage
{
    public function __construct(
        private readonly FileGenerator $generator,
        private readonly BatchPlanner $batches,
        private readonly Reporter $reporter,
        private readonly ProgressTracker $progress,
    ) {
    }

    public function handle(MorphContext $context): void
    {
        $blueprint = $context->blueprint;
        $batches = $this->batches->plan($blueprint?->files ?? []);
        $total = count($batches);
        $done = [];

        $this->progress->phase(Phase::Generate, sprintf('%d files in %d batch(es)', count($blueprint?->files ?? []), $total));

        foreach ($batches as $index => $batch) {
            $number = $index + 1;

            foreach ($batch as $spec) {
                $this->progress->file($spec->path, FileState::Generating);
            }

            $names = implode(', ', array_map(fn (FileSpec $spec) => basename($spec->path), $batch));
            $files = $this->reporter->spin(
                "Batch {$number}/{$total}: asking the AI for {$names}...",
                fn () => $this->generator->generate($context->prompt, $blueprint, $context->projectContext, $batch, $done),
            );

            foreach ($files as $file) {
                $this->progress->file($file->path(), FileState::Generated, "batch {$number}/{$total}");
            }

            $done = [...$done, ...$files];
        }

        $context->generated = $done;
        $this->progress->phaseDone(Phase::Generate, count($done).' files generated and syntax-checked');
    }
}
