<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Pipeline\Stages;

use Abdelhmed\Chimera\Contracts\ProgressTracker;
use Abdelhmed\Chimera\Contracts\ProjectScanner;
use Abdelhmed\Chimera\Enums\Phase;
use Abdelhmed\Chimera\Pipeline\MorphContext;
use Abdelhmed\Chimera\Pipeline\Stage;

final class ScanProjectStage implements Stage
{
    public function __construct(
        private readonly ProjectScanner $scanner,
        private readonly ProgressTracker $progress,
    ) {
    }

    public function handle(MorphContext $context): void
    {
        $this->progress->phase(Phase::Scan, 'reading models, controllers, routes and migrations');

        $count = 0;
        $context->projectContext = $this->scanner->scan(function (string $path) use (&$count): void {
            $count++;
            $this->progress->detail("analysing {$path}");
        });

        $this->progress->phaseDone(Phase::Scan, "{$count} files analysed with the AST");
    }
}
