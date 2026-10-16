<?php

namespace Abdelhmed\Chimera\Console;

use Abdelhmed\Chimera\Core\AstAnalyzer;
use Abdelhmed\Chimera\AI\PromptEngine;
use Abdelhmed\Chimera\Core\SchemaMorpher;
use Abdelhmed\Chimera\Core\TestRunner;
use Abdelhmed\Chimera\Core\HistoryManager; // [تعديل 2]
use Illuminate\Console\Command;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\table;

class MorphCommand extends Command
{
    protected $signature = 'chimera:morph 
                            {prompt? : The architectural request for Chimera}
                            {--dry-run : Preview changes without writing files or running migrations}';

    protected $description = 'Analyze project and autonomously generate/modify full-stack Laravel features using AI.';

  
    protected array $healedMigrations = [];

    public function handle(): int
    {
        // [تعديل 1] أداة تطوير: ممنوعة على production
        if (app()->isProduction()) {
            error("Chimera is a development tool and will not run in production.");
            return self::FAILURE;
        }

        $prompt = $this->argument('prompt');
        $isDryRun = $this->option('dry-run');

        $history = new HistoryManager();
        if (!$isDryRun && !$history->isReady()) {
            error("Chimera needs a Git repository with a clean working tree. Commit or stash your changes first.");
            return self::FAILURE;
        }
        $baseCommit = $history->head();

        if (!$prompt) {
            $prompt = \Laravel\Prompts\text(
                label: 'What would you like Chimera to build or modify for you?',
                placeholder: 'e.g., Create a multi-vendor checkout system with Stripe and real-time events',
                required: true
            );
        }

        info("Chimera is scanning your project architecture...");

        $projectContext = spin(
            callback: fn () => (new AstAnalyzer())->scanProject(),
            message: 'Analyzing AST layers (Models, Controllers, Migrations, Routes)...'
        );

        info("Consulting Chimera AI Engine (Multi-Step Architectural Analysis)...");

        $promptEngine = new PromptEngine();
        $executionPlan = spin(
            callback: fn () => $promptEngine->generateAdaptivePlan($prompt, $projectContext),
            message: 'Analyzing prompt scope and building architectural plan...'
        );

        if (!$executionPlan) {
            error("Chimera failed to generate a valid implementation plan.");
            return self::FAILURE;
        }

        if (empty($executionPlan['migrations'])
            && empty($executionPlan['code_modifications'])
            && empty($executionPlan['frontend_modifications'])) {
            error("Chimera returned an empty plan. Nothing to execute.");
            return self::FAILURE;
        }

        $this->displayImpactAnalysis($executionPlan);

        if ($isDryRun) {
            warning("🔍 [Dry Run Mode] No files were written to disk and no migrations were executed.");
            return self::SUCCESS;
        }

        $proceed = confirm(
            label: 'Do you want Chimera to execute this architectural plan?',
            default: true
        );

        if (!$proceed) {
            info("Operation cancelled by user.");
            return self::SUCCESS;
        }

        $schemaMorpher = new SchemaMorpher();

        try {
            $report = spin(
                callback: fn () => $schemaMorpher->execute($executionPlan, $prompt),
                message: 'Executing architectural morphing & database migrations...'
            );
        } catch (\Throwable $e) {
            error("Execution aborted: " . $e->getMessage());
            warning("Check your working tree with `git status` before continuing.");
            return self::FAILURE;
        }

        info("✨ Chimera successfully morphed your application architecture!");

        $report = is_array($report) ? $report : [];
        foreach ($report['skipped'] ?? [] as $skipped) {
            warning("Skipped: {$skipped}");
        }

        if (!empty($executionPlan['tests'])) {
            $this->runSelfHealingSuite($prompt, $executionPlan, $promptEngine, $schemaMorpher);
        }

        $snapshot = $history->recordSnapshot(
            $prompt,
            $executionPlan,
            $baseCommit,
            array_merge($report['migrations'] ?? [], $this->healedMigrations)
        );
        if ($snapshot) {
            info("📌 Checkpoint saved. Run `php artisan chimera:rollback` to undo this change.");
        } else {
            warning("No checkpoint was recorded (no file changes were detected).");
        }

        return self::SUCCESS;
    }

    
    protected function runSelfHealingSuite(string $prompt, array $executionPlan, PromptEngine $promptEngine, SchemaMorpher $schemaMorpher): void
    {
        $maxRetries = 3;
        $attempt = 0;
        $testRunner = new TestRunner();

        while ($attempt < $maxRetries) {
            info("Running automated test suite (Attempt " . ($attempt + 1) . ")...");

            $testResult = $testRunner->runTests($executionPlan['tests'] ?? []);

            if ($testResult['success']) {
                info("✅ All generated tests passed successfully! Architecture is robust.");
                break;
            }

            warning("⚠️ Test suite failed. Triggering Chimera Self-Healing Engine...");
            $attempt++;

            if ($attempt >= $maxRetries) {
                error("❌ Self-healing reached max attempts. Please review test output manually.");
                $this->line($testResult['output']);
                break;
            }

            $healedPlan = spin(
                callback: fn () => $promptEngine->healCode($prompt, $executionPlan, $testResult['output']),
                message: 'Analyzing test failures and rewriting code...'
            );

            if ($healedPlan) {
                try {
                    $healReport = $schemaMorpher->execute($healedPlan, $prompt);
                } catch (\Throwable $e) {
                    error("Healed plan was rejected: " . $e->getMessage());
                    break;
                }
                if (is_array($healReport)) {
                    $this->healedMigrations = array_merge($this->healedMigrations, $healReport['migrations'] ?? []);
                }

              foreach (['migrations' => 'name', 'code_modifications' => 'file', 'frontend_modifications' => 'file', 'tests' => 'file'] as $key => $idField) {
                    $merged = [];
                    foreach (array_merge($executionPlan[$key] ?? [], $healedPlan[$key] ?? []) as $item) {
                        $merged[$item[$idField] ?? md5(json_encode($item))] = $item;
                    }
                    $executionPlan[$key] = array_values($merged);
                }
            } else {
                error("Failed to generate a healed plan.");
                break;
            }
        }
    }

 
    protected function displayImpactAnalysis(array $plan): void
    {
        $impact = $plan['impact_analysis'] ?? [];
        $hasBreakingChanges = $impact['has_breaking_changes'] ?? false;

        if ($hasBreakingChanges) {
            $this->warn("⚠️ Warning: This plan includes potential breaking changes.");
        }

        $warnings = $impact['warnings'] ?? [];
        if (!empty($warnings)) {
            $this->line("\n<fg=yellow>Warnings & Mitigations:</>");
            foreach ($warnings as $warning) {
                $this->line(" - " . $warning);
            }
        }

        $mitigations = $impact['mitigations'] ?? [];
        if (!empty($mitigations)) {
            $this->line("\n<fg=green>Mitigations:</>");
            foreach ($mitigations as $mitigation) {
                $this->line(" - " . $mitigation);
            }
        }

        $migrations = $plan['migrations'] ?? [];
        $modifications = $plan['code_modifications'] ?? [];
        $frontend = $plan['frontend_modifications'] ?? [];
        $tests = $plan['tests'] ?? [];

        $rows = [];
        foreach ($migrations as $m) {
            $rows[] = ['Migration', $m['name'] ?? 'Unknown', 'CREATE'];
        }
        foreach ($modifications as $mod) {
            $rows[] = ['Code File', $mod['file'] ?? 'Unknown', $mod['action'] ?? 'MODIFY'];
        }
        foreach ($frontend as $f) {
            $rows[] = ['Frontend View', $f['file'] ?? 'Unknown', 'CREATE'];
        }
        foreach ($tests as $t) {
            $rows[] = ['Test File', $t['file'] ?? 'Unknown', 'CREATE'];
        }

        if (!empty($rows)) {
            $this->line("\n<fg=cyan>Planned File Operations:</>");
            table(['Type', 'Target Path', 'Action'], $rows);
        }
    }
}