<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Pipeline;

use Abdelhmed\Chimera\Conventions\ConventionSet;
use Abdelhmed\Chimera\Data\Blueprint;
use Abdelhmed\Chimera\Data\ExecutionReport;
use Abdelhmed\Chimera\Data\GeneratedFile;
use Abdelhmed\Chimera\Data\TestResult;
use Abdelhmed\Chimera\Trust\TrustReport;

/** The state that travels through the pipeline stages. */
final class MorphContext
{
    /** @var array<string, mixed> */
    public array $projectContext = [];

    public ?Blueprint $blueprint = null;

    /** @var list<GeneratedFile> */
    public array $generated = [];

    public ?string $checkpointId = null;

    public ?ExecutionReport $report = null;

    public ?TestResult $testResult = null;

    public ?ConventionSet $conventions = null;

    /** @var list<string> convention violations that could not be fixed automatically */
    public array $conventionWarnings = [];

    public ?TrustReport $trust = null;

    /** @var list<string> files the AI had to fix after failing tests */
    public array $healedPaths = [];

    private ?string $haltReason = null;

    private bool $failed = false;

    public function __construct(
        public readonly string $prompt,
        public readonly bool $dryRun = false,
        public readonly bool $assumeYes = false,
        public readonly bool $runMigrations = true,
        public readonly bool $runTests = true,
        public readonly bool $useConventions = true,
        public readonly bool $runTrust = true,
    ) {
    }

    public function halt(string $reason, bool $failure = false): void
    {
        $this->haltReason = $reason;
        $this->failed = $failure;
    }

    public function isHalted(): bool
    {
        return $this->haltReason !== null;
    }

    public function haltReason(): ?string
    {
        return $this->haltReason;
    }

    /** True when the run ended in an error or when the generated tests still fail. */
    public function hasFailed(): bool
    {
        return $this->failed || ($this->testResult?->hasFailed() ?? false);
    }
}
