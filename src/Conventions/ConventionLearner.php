<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions;

use Abdelhmed\Chimera\Contracts\ProjectFiles;
use Abdelhmed\Chimera\Conventions\Detectors\ConventionDetector;
use Throwable;

/** Reads the project's own code (through the AST) and writes down the habits it finds. */
final class ConventionLearner
{
    /** @param list<ConventionDetector> $detectors */
    public function __construct(
        private readonly ProjectFiles $files,
        private readonly array $detectors,
        private readonly int $maxFilesPerDirectory = 400,
    ) {
    }

    public function learn(?ConventionSet $previous = null): ConventionSet
    {
        $corpus = new ProjectCorpus($this->files, $this->maxFilesPerDirectory);
        $found = [];

        foreach ($this->detectors as $detector) {
            try {
                array_push($found, ...$detector->detect($corpus));
            } catch (Throwable) {
                // One detector failing on unusual code must never stop the others.
            }
        }

        return (new ConventionSet($found, $corpus->analysed()))->mergeWith($previous);
    }
}
