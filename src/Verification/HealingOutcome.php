<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Verification;

use Abdelhmed\Chimera\Data\GeneratedFile;
use Abdelhmed\Chimera\Data\TestResult;

final class HealingOutcome
{
    /**
     * @param  list<GeneratedFile>  $generated  the generated files after any fixes
     * @param  list<string>  $healedPaths  files the AI had to fix
     */
    public function __construct(
        public readonly TestResult $result,
        public readonly array $generated,
        public readonly int $attempts,
        public readonly array $healedPaths = [],
    ) {
    }
}
