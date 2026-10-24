<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Pipeline\Stages;

use Abdelhmed\Chimera\Contracts\ProgressTracker;
use Abdelhmed\Chimera\Contracts\Reporter;
use Abdelhmed\Chimera\Conventions\ConventionChecker;
use Abdelhmed\Chimera\Conventions\ConventionFeedback;
use Abdelhmed\Chimera\Conventions\Violation;
use Abdelhmed\Chimera\Data\GeneratedFile;
use Abdelhmed\Chimera\Enums\Phase;
use Abdelhmed\Chimera\Exceptions\ChimeraException;
use Abdelhmed\Chimera\Generation\FileGenerator;
use Abdelhmed\Chimera\Pipeline\MorphContext;
use Abdelhmed\Chimera\Pipeline\Stage;

/** Checks the generated code against the enforceable conventions (AST, no AI) and asks the AI to fix violations before anything is written. */
final class ConventionAuditStage implements Stage
{
    public function __construct(
        private readonly ConventionChecker $checker,
        private readonly FileGenerator $generator,
        private readonly Reporter $reporter,
        private readonly ProgressTracker $progress,
        private readonly int $maxRepairs = 1,
    ) {
    }

    public function handle(MorphContext $context): void
    {
        $set = $context->conventions;

        if ($set === null || $context->blueprint === null || ! $this->checker->canEnforce($set)) {
            return;
        }

        $this->progress->phase(Phase::Audit, 'checking the generated code against '.count($set->enabled()).' conventions');

        $violations = $this->checker->check($context->generated, $set);
        $initial = $violations;

        for ($attempt = 0; $attempt < $this->maxRepairs && $violations !== []; $attempt++) {
            if (! $this->repair($context, $violations)) {
                break;
            }

            $violations = $this->checker->check($context->generated, $set);
        }

        $context->conventionWarnings = array_map(fn (Violation $v) => "{$v->path}: {$v->message}", $violations);

        $this->progress->phaseDone(Phase::Audit, match (true) {
            $violations !== [] => count($violations).' violation(s) could not be fixed automatically (listed in the summary)',
            $initial !== [] => sprintf('fixed %d violation(s) in %d file(s) before writing anything', count($initial), count(array_unique(array_map(fn (Violation $v) => $v->path, $initial)))),
            default => 'every generated file follows your conventions',
        });
    }

    /** @param list<Violation> $violations */
    private function repair(MorphContext $context, array $violations): bool
    {
        $broken = array_flip(array_map(fn (Violation $v) => $v->path, $violations));
        $specs = [];
        $others = [];

        foreach ($context->generated as $file) {
            isset($broken[$file->path()]) ? $specs[] = $file->spec : $others[] = $file;
        }

        $feedback = ConventionFeedback::build($violations, $context->generated);

        try {
            $fixed = $this->reporter->spin(
                'Asking the AI to fix '.count($specs).' file(s) that break your conventions...',
                fn () => $this->generator->generate($context->prompt, $context->blueprint, $context->projectContext, $specs, $others, $feedback),
            );
        } catch (ChimeraException $e) {
            $this->reporter->warn('Could not fix the convention violations automatically: '.$e->getMessage());

            return false;
        }

        $byPath = [];
        foreach ($fixed as $file) {
            $byPath[$file->path()] = $file;
        }

        $context->generated = array_map(fn (GeneratedFile $f) => $byPath[$f->path()] ?? $f, $context->generated);

        return true;
    }
}
