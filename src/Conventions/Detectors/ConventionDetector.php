<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions\Detectors;

use Abdelhmed\Chimera\Conventions\Convention;
use Abdelhmed\Chimera\Conventions\ProjectCorpus;

interface ConventionDetector
{
    /** @return list<Convention> only habits that clearly dominate the project */
    public function detect(ProjectCorpus $corpus): array;
}
