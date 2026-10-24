<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Pipeline\Stages;

use Abdelhmed\Chimera\Contracts\ProgressTracker;
use Abdelhmed\Chimera\Enums\Phase;
use Abdelhmed\Chimera\Pipeline\MorphContext;
use Abdelhmed\Chimera\Pipeline\Stage;
use Abdelhmed\Chimera\Trust\TrustAnalyzer;

/** Green tests are not proof. Plant deliberate bugs in the generated code and see whether the tests notice. */
final class TrustScoreStage implements Stage
{
    public function __construct(
        private readonly TrustAnalyzer $analyzer,
        private readonly ProgressTracker $progress,
    ) {
    }

    public function handle(MorphContext $context): void
    {
        if (! $context->runTrust || $context->testResult === null || ! $context->testResult->passed) {
            return;
        }

        $this->progress->phase(Phase::Trust, 'planting deliberate bugs in the generated code to see whether the tests notice');

        $report = $this->analyzer->analyze($context->generated, $this->progress);
        $context->trust = $report;

        $this->progress->phaseDone(Phase::Trust, $report->wasSkipped()
            ? 'skipped: '.$report->skippedReason
            : sprintf('trust score %d%% (%d of %d bugs caught)', $report->score(), $report->killed(), $report->total()));
    }
}
