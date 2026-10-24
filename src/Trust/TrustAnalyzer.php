<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Trust;

use Abdelhmed\Chimera\Contracts\ProgressTracker;
use Abdelhmed\Chimera\Contracts\ProjectFiles;
use Abdelhmed\Chimera\Data\GeneratedFile;
use Abdelhmed\Chimera\Enums\FileAction;
use Abdelhmed\Chimera\Enums\FileType;
use Abdelhmed\Chimera\Exceptions\ChimeraException;

/**
 * Mutation testing for AI-written code. The generated tests passed, but would they notice a bug?
 * Chimera plants small deliberate bugs in the generated application code and counts how many the tests catch.
 */
final class TrustAnalyzer
{
    /** Files with behaviour worth mutating. Migrations, routes, views, factories and tests are not. */
    private const TARGET_TYPES = [
        FileType::Controller, FileType::Service, FileType::Request, FileType::Policy, FileType::Middleware,
        FileType::Model, FileType::Job, FileType::Listener, FileType::Resource,
    ];

    public function __construct(
        private readonly ProjectFiles $files,
        private readonly MutantGenerator $generator,
        private readonly MutationRunner $runner,
        private readonly int $maxMutants = 20,
    ) {
    }

    /** @param list<GeneratedFile> $generated */
    public function analyze(array $generated, ProgressTracker $progress): TrustReport
    {
        $tests = array_values(array_unique(array_map(
            fn (GeneratedFile $f) => $f->path(),
            array_filter($generated, fn (GeneratedFile $f) => $f->type() === FileType::Test && $f->spec->isPhp()),
        )));

        if ($tests === []) {
            return TrustReport::skipped('no tests were generated');
        }

        $targets = array_values(array_filter($generated, fn (GeneratedFile $f) => $this->isTarget($f)));

        if ($targets === []) {
            return TrustReport::skipped('the generated code has no logic that can be stress-tested');
        }

        $perFile = max(2, (int) ceil($this->maxMutants / count($targets)));
        $queue = $this->interleave(array_map(
            fn (GeneratedFile $f) => $this->generator->generate($f->path(), $this->files->get($f->path()), $perFile),
            $targets,
        ));
        $queue = array_slice($queue, 0, $this->maxMutants);

        if ($queue === []) {
            return TrustReport::skipped('no comparisons, validation rules or guards were found to stress-test');
        }

        $results = [];
        $total = count($queue);

        try {
            foreach ($queue as $index => $mutation) {
                $progress->detail(sprintf('bug %d/%d: %s:%d %s', $index + 1, $total, $mutation->path, $mutation->line, $mutation->description));
                $results[] = $this->runner->run($mutation, $tests);
            }
        } catch (ChimeraException $e) {
            return TrustReport::skipped($e->getMessage());
        }

        return new TrustReport($results);
    }

    private function isTarget(GeneratedFile $file): bool
    {
        return $file->spec->isPhp()
            && in_array($file->type(), self::TARGET_TYPES, true)
            && in_array($file->action(), [FileAction::Create, FileAction::Modify], true)
            && $this->files->exists($file->path());
    }

    /**
     * Take one mutant from each file in turn so a big file cannot crowd out the others.
     *
     * @param  list<list<Mutation>>  $perFile
     * @return list<Mutation>
     */
    private function interleave(array $perFile): array
    {
        $queue = [];

        for ($round = 0; array_filter($perFile, fn (array $m) => isset($m[$round])) !== []; $round++) {
            foreach ($perFile as $mutations) {
                if (isset($mutations[$round])) {
                    $queue[] = $mutations[$round];
                }
            }
        }

        return $queue;
    }
}
