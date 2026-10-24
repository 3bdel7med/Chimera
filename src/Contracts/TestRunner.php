<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Contracts;

use Abdelhmed\Chimera\Data\TestResult;

interface TestRunner
{
    /**
     * @param  list<string>  $testPaths  test files relative to the project root
     * @param  int|null  $timeout  seconds per test file; the runner's default when null
     * @param  bool  $stopOnFailure  stop at the first failing file (enough to know a mutant was caught)
     */
    public function run(array $testPaths, ?int $timeout = null, bool $stopOnFailure = false): TestResult;
}
