<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Verification;

use Abdelhmed\Chimera\Contracts\TestRunner;
use Abdelhmed\Chimera\Data\TestResult;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/** Runs the generated test files one by one with Pest or PHPUnit, against an isolated in-memory database. */
final class ProcessTestRunner implements TestRunner
{
    private const OUTPUT_TAIL = 8000;

    /** @param array<string, string> $env */
    public function __construct(
        private readonly string $basePath,
        private readonly array $env = [],
        private readonly int $timeout = 300,
    ) {
    }

    public function run(array $testPaths, ?int $timeout = null, bool $stopOnFailure = false): TestResult
    {
        $binary = $this->binary();

        if ($binary === null) {
            return TestResult::skip('Neither vendor/bin/pest nor vendor/bin/phpunit was found.');
        }

        if ($testPaths === []) {
            return TestResult::skip('No test files were generated.');
        }

        $limit = $timeout ?? $this->timeout;
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $output = '';
        $passed = true;

        foreach ($testPaths as $path) {
            $process = new Process([$php, $binary, $path], $this->basePath, $this->env, null, $limit);

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                return TestResult::fail($this->tail($output."\nTimed out after {$limit}s while running {$path}."));
            }

            $output .= "\n\$ {$binary} {$path}\n".trim($process->getOutput()."\n".$process->getErrorOutput())."\n";
            $passed = $passed && $process->isSuccessful();

            if (! $passed && $stopOnFailure) {
                break;
            }
        }

        return $passed ? TestResult::pass($this->tail($output)) : TestResult::fail($this->tail($output));
    }

    private function binary(): ?string
    {
        foreach (['vendor/bin/pest', 'vendor/bin/phpunit'] as $candidate) {
            if (is_file(rtrim($this->basePath, '/\\').'/'.$candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function tail(string $output): string
    {
        return strlen($output) > self::OUTPUT_TAIL ? '...'.substr($output, -self::OUTPUT_TAIL) : $output;
    }
}
