<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Pipeline\Stages;

use Abdelhmed\Chimera\Contracts\ProgressTracker;
use Abdelhmed\Chimera\Contracts\Reporter;
use Abdelhmed\Chimera\Enums\Phase;
use Abdelhmed\Chimera\Pipeline\MorphContext;
use Abdelhmed\Chimera\Pipeline\Stage;
use Abdelhmed\Chimera\Planning\BlueprintPlanner;

/** Phase 1: the AI answers with the list of files to build. */
final class PlanBlueprintStage implements Stage
{
    public function __construct(
        private readonly BlueprintPlanner $planner,
        private readonly Reporter $reporter,
        private readonly ProgressTracker $progress,
    ) {
    }

    public function handle(MorphContext $context): void
    {
        $this->progress->phase(Phase::Plan, 'sending your request and the project summary to the AI');

        $context->blueprint = $this->reporter->spin(
            'Waiting for the blueprint...',
            fn () => $this->planner->plan($context->prompt, $context->projectContext),
        );

        $this->progress->phaseDone(Phase::Plan, count($context->blueprint->files).' files planned');
    }
}
