<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Pipeline\Stages;

use Abdelhmed\Chimera\Contracts\ProgressTracker;
use Abdelhmed\Chimera\Contracts\Reporter;
use Abdelhmed\Chimera\Enums\FileState;
use Abdelhmed\Chimera\Enums\Phase;
use Abdelhmed\Chimera\Pipeline\MorphContext;
use Abdelhmed\Chimera\Pipeline\Stage;
use Abdelhmed\Chimera\Verification\HealingService;

/** Runs the generated tests and lets the AI repair them when they fail. */
final class VerifyAndHealStage implements Stage
{
    public function __construct(
        private readonly HealingService $healing,
        private readonly Reporter $reporter,
        private readonly ProgressTracker $progress,
    ) {
    }

    public function handle(MorphContext $context): void
    {
        if (! $context->runTests || $context->blueprint === null || $context->checkpointId === null) {
            return;
        }

        $this->progress->phase(Phase::Verify, 'running the generated tests on an isolated database');

        $outcome = $this->healing->run($context->prompt, $context->blueprint, $context->generated, $context->checkpointId, $this->reporter);

        $context->generated = $outcome->generated;
        $context->testResult = $outcome->result;
        $context->healedPaths = $outcome->healedPaths;

        foreach ($outcome->healedPaths as $path) {
            $this->progress->file($path, FileState::Healed);
        }

        $result = $outcome->result;
        $this->progress->phaseDone(Phase::Verify, match (true) {
            ! $result->ran => 'skipped: '.$result->note,
            $result->passed => 'tests pass'.($outcome->attempts > 0 ? " after {$outcome->attempts} healing attempt(s)" : ''),
            default => 'tests still failing after '.$outcome->attempts.' healing attempt(s)',
        });
    }
}
