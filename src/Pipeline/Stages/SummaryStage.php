<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Pipeline\Stages;

use Abdelhmed\Chimera\Contracts\ProgressTracker;
use Abdelhmed\Chimera\Contracts\Reporter;
use Abdelhmed\Chimera\Pipeline\MorphContext;
use Abdelhmed\Chimera\Pipeline\Stage;

/** Final report: the file tree with each file's state, and what the developer still has to do by hand. */
final class SummaryStage implements Stage
{
    public function __construct(
        private readonly Reporter $reporter,
        private readonly ProgressTracker $progress,
    ) {
    }

    public function handle(MorphContext $context): void
    {
        $this->progress->summary();

        if ($context->testResult?->hasFailed()) {
            $this->reporter->error("Generated tests still fail:\n".$context->testResult->output);
        }

        $this->reportTrust($context);

        if ($context->conventionWarnings !== []) {
            $this->reporter->warn("Some generated code still breaks your conventions:\n".implode("\n", array_map(fn (string $w) => "  - {$w}", $context->conventionWarnings)));
        }

        $blueprint = $context->blueprint;

        if ($blueprint !== null && $blueprint->composerPackages !== []) {
            $this->reporter->warn('Install the packages this feature needs: composer require '.implode(' ', $blueprint->composerPackages));
        }

        foreach ($blueprint?->envVariables ?? [] as $variable) {
            $this->reporter->warn("Add to your .env: {$variable}");
        }

        $this->reporter->info("Review the changes, then commit. To undo everything: php artisan chimera:rollback {$context->checkpointId}");
    }

    private function reportTrust(MorphContext $context): void
    {
        $trust = $context->trust;

        if ($trust === null || $trust->wasSkipped()) {
            return;
        }

        $line = sprintf('Trust score %d%%: the tests caught %d of %d deliberate bugs.', $trust->score(), $trust->killed(), $trust->total());

        if ($trust->survivors() === []) {
            $this->reporter->success($line);

            return;
        }

        $this->reporter->warn($line."\nThese bugs went unnoticed, so the tests do not cover them yet:\n"
            .implode("\n", array_map(fn ($r) => '  - '.$r->label(), $trust->survivors())));
    }
}
