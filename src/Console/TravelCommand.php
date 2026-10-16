<?php

namespace Abdelhmed\Chimera\Console;
use Illuminate\Support\Str;
use Abdelhmed\Chimera\Core\HistoryManager;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use function Laravel\Prompts\select;
use function Laravel\Prompts\info;
use function Laravel\Prompts\error;
use function Laravel\Prompts\confirm;

class TravelCommand extends Command
{
    protected $signature = 'chimera:travel';
    protected $description = 'Time travel to any previous architectural checkpoint created by Chimera.';

    public function handle(): int
    {
        $historyManager = new HistoryManager();
        $history = $historyManager->getHistory();

        if (empty($history)) {
            error("No architectural history found for time traveling.");
            return self::FAILURE;
        }

        $choices = [];
        foreach ($history as $index => $snapshot) {
            $id = $snapshot['id'] ?? $index;
            $time = $snapshot['timestamp'] ?? 'Unknown time';
            $prompt = $snapshot['prompt'] ?? 'Custom Prompt';
            
            $choices[$id] = "[{$time}] - " . Str::limit($prompt, 60);
        }

        $selectedId = select(
            label: 'Select a checkpoint to travel back to:',
            options: $choices
        );

        $targetIndex = null;
        foreach ($history as $index => $snapshot) {
            if (($snapshot['id'] ?? $index) === $selectedId) {
                $targetIndex = $index;
                break;
            }
        }

        if ($targetIndex === null) {
            error("Invalid checkpoint selected.");
            return self::FAILURE;
        }

        $targetSnapshot = $history[$targetIndex];
        $gitCommit = $targetSnapshot['git_commit'] ?? null;

        if (!$gitCommit) {
            error("This checkpoint does not have an associated Git commit hash.");
            return self::FAILURE;
        }

        $confirmed = confirm(
            label: "Are you sure you want to travel to this point? (All later changes will be discarded)",
            default: false
        );

        if (!$confirmed) {
            info("Time travel cancelled.");
            return self::SUCCESS;
        }

        if (file_exists(base_path('.git'))) {
            $process = new Process(['git', 'reset', '--hard', $gitCommit]);
            $process->run();

            if (!$process->isSuccessful()) {
                error("Failed to execute git reset for time travel.");
                return self::FAILURE;
            }
        }

        $newHistory = array_slice($history, $targetIndex);
        $historyPath = storage_path('app/chimera/history.json');
        file_put_contents($historyPath, json_encode($newHistory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        info("🚀 Successfully traveled back in time to the selected architecture state!");

        return self::SUCCESS;
    }
}