<?php

namespace Abdelhmed\Chimera\Console;

use Abdelhmed\Chimera\Core\HistoryManager;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process; 
use function Laravel\Prompts\info;
use function Laravel\Prompts\error;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning; // [تعديل 5]

class RollbackCommand extends Command
{
    protected $signature = 'chimera:rollback';
    protected $description = 'Rollback the last architectural change made by Chimera AI.';

    public function handle(): int
    {
        if (app()->isProduction()) {
            error("Chimera is a development tool and will not run in production.");
            return self::FAILURE;
        }

        $historyManager = new HistoryManager();
        $history = $historyManager->getHistory();

        if (empty($history)) {
            error("No history records found to rollback.");
            return self::FAILURE;
        }

        $latest = $history[0];
        $prompt = $latest['prompt'] ?? 'Unknown prompt';
        $gitCommit = $latest['git_commit'] ?? null;

        $this->info("Last Action: " . $prompt);
        $this->info("Timestamp: " . ($latest['timestamp'] ?? 'Unknown'));

       if (!$gitCommit || empty($latest['base_commit'])) {
            error("This record has no Git checkpoint, so it cannot be rolled back automatically.");
            return self::FAILURE;
        }

        if (!$historyManager->isReady()) {
            error("Chimera needs a Git repository with a clean working tree. Commit or stash your changes first.");
            return self::FAILURE;
        }

        if ($historyManager->head() !== $gitCommit) {
            error("New commits were made after this Chimera checkpoint. Rolling back would discard them, so it was aborted.");
            return self::FAILURE;
        }

        $migrationsCount = count($latest['migrations'] ?? []);
        if ($migrationsCount > 0) {
            $this->info("Migrations to roll back: " . $migrationsCount);
        }

        $confirmed = confirm(
            label: 'Are you sure you want to rollback this change?',
            default: true
        );

        if (!$confirmed) {
            info("Rollback cancelled.");
            return self::SUCCESS;
        }

       try {
            $rolledBack = $historyManager->rollbackLatest();
        } catch (\Throwable $e) {
            error("Rollback failed: " . $e->getMessage());
            return self::FAILURE;
        }

        if (!$rolledBack) {
            error("Failed to execute the rollback. Please check your git status.");
            return self::FAILURE;
        }

        info("✨ Successfully rolled back the last Chimera modification!");

        if ($migrationsCount > 0) {
            warning("If a migration had an empty down() method, its tables may still exist. Check with: php artisan migrate:status");
        }

        return self::SUCCESS;
    }
}