<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Pipeline\Stages;

use Abdelhmed\Chimera\Contracts\ConventionStore;
use Abdelhmed\Chimera\Contracts\ProgressTracker;
use Abdelhmed\Chimera\Contracts\Reporter;
use Abdelhmed\Chimera\Conventions\ConventionLearner;
use Abdelhmed\Chimera\Enums\Phase;
use Abdelhmed\Chimera\Pipeline\MorphContext;
use Abdelhmed\Chimera\Pipeline\Stage;

/** Loads the project's conventions (or learns them on the fly) so the AI writes code in the project's own style. */
final class LoadConventionsStage implements Stage
{
    public function __construct(
        private readonly ConventionStore $store,
        private readonly ConventionLearner $learner,
        private readonly ProgressTracker $progress,
        private readonly Reporter $reporter,
    ) {
    }

    public function handle(MorphContext $context): void
    {
        if (! $context->useConventions) {
            return;
        }

        $this->progress->phase(Phase::Conventions, 'how your project writes validation, responses, models and tests');

        $set = $this->store->load();
        $source = 'from your saved conventions';

        if ($set === null) {
            $set = $this->learner->learn();
            $source = 'learned from your code just now';

            if (! $set->isEmpty()) {
                $this->reporter->info('Tip: run `php artisan chimera:learn` to review these conventions and save them for your team.');
            }
        }

        $context->conventions = $set;
        $context->projectContext['conventions'] = $set->rules();

        $this->progress->phaseDone(Phase::Conventions, count($set->enabled())." active conventions, {$source}");
    }
}
