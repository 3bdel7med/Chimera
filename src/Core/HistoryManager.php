<?php

namespace Abdelhmed\Chimera\Core;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class HistoryManager
{
    protected string $historyPath;

   
    protected ?string $lastError = null;

    public function __construct()
    {
        $this->historyPath = storage_path('app/chimera/history.json');
    }

  
    protected function git(array $args): Process
    {
        $process = new Process(['git', ...$args], base_path());
        $process->run();

        return $process;
    }

 
    public function isReady(): bool
    {
        if (!File::exists(base_path('.git'))) {
            return false;
        }

        $status = $this->git(['status', '--porcelain']);

        return $status->isSuccessful() && trim($status->getOutput()) === '';
    }

   
    public function head(): ?string
    {
        $process = $this->git(['rev-parse', 'HEAD']);

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

 
    public function recordSnapshot(string $prompt, array $executionPlan, ?string $baseCommit = null, array $migrationPaths = []): ?array
    {
        $history = $this->getHistory();

        $gitCommitHash = $this->createGitCheckpoint($prompt);
      if ($gitCommitHash === null && File::exists(base_path('.git'))) {
            return null;
        }

        $snapshot = [
            'id'           => uniqid('chimera_', true),
            'timestamp'    => now()->toIso8601String(),
            'prompt'       => $prompt,
            'base_commit'  => $baseCommit ?? ($gitCommitHash ? $this->parentOf($gitCommitHash) : null), // [تعديل 4]
            'git_commit'   => $gitCommitHash,
            'migrations'   => $migrationPaths,
            'plan_summary' => [
                'migrations_count' => count($executionPlan['migrations'] ?? []),
                'modifications_count' => count($executionPlan['code_modifications'] ?? []),
            ]
        ];

        array_unshift($history, $snapshot);

        $history = array_slice($history, 0, 50);

        $this->save($history); 

        return $snapshot;
    }

  
    public function getHistory(): array
    {
        if (!File::exists($this->historyPath)) {
            return [];
        }

        $content = File::get($this->historyPath);
        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

   
    public function rollbackLatest(): bool
    {
        $history = $this->getHistory();
        $snapshot = $history[0] ?? null;

        if (!$snapshot || empty($snapshot['git_commit']) || empty($snapshot['base_commit'])) {
            return false;
        }

        if (!$this->isReady() || $this->head() !== $snapshot['git_commit']) {
            return false;
        }

        $this->undoMigrations($snapshot);

        if (!$this->git(['reset', '--hard', $snapshot['base_commit']])->isSuccessful()) {
            return false;
        }

        $this->save(array_slice($history, 1));

        return true;
    }

  
    public function travelTo(string $id): bool
    {
        $history = $this->getHistory();
        $index = array_search($id, array_column($history, 'id'), true);

        if ($index === false || empty($history[$index]['git_commit'])) {
            return false;
        }

        $target = $history[$index]['git_commit'];

        if (!$this->isReady() || !$this->isAncestor($target)) {
            return false;
        }

        foreach (array_slice($history, 0, $index) as $newer) {
            $this->undoMigrations($newer);
        }

        if (!$this->git(['reset', '--hard', $target])->isSuccessful()) {
            return false;
        }

        $this->save(array_slice($history, $index));

        return true;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

   
    protected function undoMigrations(array $snapshot): void
    {
        foreach (array_reverse($snapshot['migrations'] ?? []) as $path) {
            if (File::exists(base_path($path))) {
                Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
            }
        }
    }

  
    protected function save(array $history): void
    {
        $directory = dirname($this->historyPath);
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($this->historyPath, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    
    protected function createGitCheckpoint(string $prompt): ?string
    {
        $this->lastError = null;

        if (!File::exists(base_path('.git'))) {
            return null;
        }

        try {
            $this->git(['add', '.']);

          $clean = preg_replace('/\s+/u', ' ', $prompt) ?? $prompt;
            $commit = $this->git(['commit', '-m', 'Chimera AI Checkpoint: ' . mb_substr($clean, 0, 50)]);

            if (!$commit->isSuccessful()) {
                $this->lastError = trim($commit->getErrorOutput() . "\n" . $commit->getOutput());
                return null;
            }

            return $this->head();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
        }

        return null;
    }

 
    protected function parentOf(string $commit): ?string
    {
        $process = $this->git(['rev-parse', $commit . '^']);

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

   
    protected function isAncestor(string $commit): bool
    {
        return $this->git(['merge-base', '--is-ancestor', $commit, 'HEAD'])->isSuccessful();
    }
}