<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Trust;

use Abdelhmed\Chimera\Contracts\MutationRecovery;
use Abdelhmed\Chimera\Contracts\ProjectFiles;
use Abdelhmed\Chimera\Contracts\TestRunner;
use Abdelhmed\Chimera\Exceptions\ChimeraException;

/** Plants one bug on disk, runs the tests, and always puts the original file back. */
final class MutationRunner
{
    public function __construct(
        private readonly ProjectFiles $files,
        private readonly TestRunner $tests,
        private readonly MutationRecovery $recovery,
        private readonly int $timeout = 60,
    ) {
    }

    /**
     * @param  list<string>  $testPaths
     *
     * @throws ChimeraException when the tests cannot be run at all
     */
    public function run(Mutation $mutation, array $testPaths): MutantResult
    {
        $original = $this->files->get($mutation->path);
        $this->recovery->remember($mutation->path, $original);

        try {
            $this->files->put($mutation->path, $mutation->code);
            $result = $this->tests->run($testPaths, $this->timeout, true);
        } finally {
            $this->files->put($mutation->path, $original);
            $this->recovery->forget($mutation->path);
        }

        if (! $result->ran) {
            throw new ChimeraException('The tests could not be run: '.$result->note);
        }

        // A failure, an error or a timeout all mean the bug did not go unnoticed.
        return new MutantResult($mutation, $result->hasFailed() ? MutantStatus::Killed : MutantStatus::Survived);
    }
}
