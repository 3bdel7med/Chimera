<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Tests\Support;

use Abdelhmed\Chimera\Contracts\TestRunner;
use Abdelhmed\Chimera\Data\TestResult;

/** Returns scripted results in order; the last one repeats. */
final class FakeTestRunner implements TestRunner
{
    public int $runs = 0;

    /** @param list<TestResult> $results */
    public function __construct(private array $results)
    {
    }

    public function run(array $testPaths, ?int $timeout = null, bool $stopOnFailure = false): TestResult
    {
        $this->runs++;

        return count($this->results) > 1 ? array_shift($this->results) : $this->results[0];
    }
}
