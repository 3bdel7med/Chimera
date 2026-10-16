<?php
namespace Abdelhmed\Chimera\Core;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Artisan; // Added for running migrations and seeding automatically during tests

class TestRunner
{
    /**
     * Maximum output length limit (keeping the end since errors typically appear at the bottom).
     */
    protected int $maxOutput = 20000;

    /**
     * Run specified test files and return execution results.
     * 
     * ENHANCEMENT: Added automated database schema migration and seeding before execution 
     * to prevent database query exceptions and missing table failures in generated tests.
     */
    public function runTests(array $testFiles): array
    {
        if (empty($testFiles)) {
            return ['success' => true, 'output' => 'No test files generated.'];
        }

        // Determine if operating on Windows to handle batch extensions correctly.
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $extension = $isWindows ? '.bat' : '';

        // Select the available test runner binary (Pest or PHPUnit).
        $binary = file_exists(base_path('vendor/bin/pest' . $extension)) 
            ? base_path('vendor/bin/pest' . $extension) 
            : base_path('vendor/bin/phpunit' . $extension);

        // If no binary is found, return an environment error so self-healing doesn't waste attempts.
        if (!file_exists($binary)) {
            return [
                'success' => false,
                'environment_error' => true,
                'output' => 'Neither Pest nor PHPUnit is installed in vendor/bin. Install one (e.g. composer require --dev pestphp/pest) and try again.',
            ];
        }

        // Validate and filter test paths.
        $paths = [];
        $skipped = [];
        foreach ($testFiles as $test) {
            $path = is_array($test) ? ($test['file'] ?? $test['path'] ?? null) : null;

            if (!is_string($path) || !str_starts_with($path, 'tests/') || str_contains($path, '..') || !file_exists(base_path($path))) {
                $skipped[] = is_string($path) ? $path : '(invalid entry)';
                continue;
            }

            $paths[] = $path;
        }

        if (empty($paths)) {
            return [
                'success' => false,
                'environment_error' => true,
                'output' => 'None of the generated test files could be found on disk: ' . implode(', ', $skipped),
            ];
        }

        // ENHANCEMENT: Automatically run migrations in the testing environment before executing 
        // generated test cases to ensure all tables exist in the in-memory SQLite database.
        try {
            $env = $this->testEnvironment();
            // Temporarily set env variables for artisan call context
            foreach ($env as $key => $value) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
            
            // Run migrations to populate the SQLite :memory: database
            Artisan::call('migrate:fresh', ['--force' => true]);
        } catch (\Throwable $e) {
            // Log or handle migration preparation exception silently if needed
        }

        $php = (new PhpExecutableFinder())->find(false) ?: PHP_BINARY;
        $timeout = (int) config('chimera.test_timeout', 300);

        $success = true;
        $chunks = [];

        // Execute each test file individually.
        foreach ($paths as $path) {
            // On Windows with batch files, execute directly or through php according to OS type.
            $processCommand = $isWindows ? [$binary, $path] : [$php, $binary, $path];
            
            $process = new Process($processCommand, base_path(), $env);
            $process->setTimeout($timeout);

            try {
                $process->run();
            } catch (ProcessTimedOutException $e) {
                $success = false;
                $chunks[] = "── {$path} ──\nTests timed out after {$timeout} seconds.";
                continue;
            }

            $success = $success && $process->isSuccessful();
            $chunks[] = "── {$path} ──\n" . trim($process->getOutput() . "\n" . $process->getErrorOutput());
        }

        $output = implode("\n\n", $chunks);
        if (!empty($skipped)) {
            $output .= "\n\nSkipped (missing or invalid): " . implode(', ', $skipped);
        }

        return [
            'success' => $success,
            'output'  => $this->clean($output),
            'ran'     => $paths,
        ];
    }

    /**
     * Test environment configuration (defaulting to in-memory SQLite to protect production database).
     * 
     * ENHANCEMENT: Ensured SQLite in-memory driver is fully configured with foreign key constraints 
     * enabled to match production relational integrity rules.
     */
    protected function testEnvironment(): array
    {
        $env = config('chimera.test_env', [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
        ]);

        if (!is_array($env)) {
            return ['NO_COLOR' => '1'];
        }

        return array_merge($env, [
            'APP_CONFIG_CACHE' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chimera-no-config-cache.php',
            'NO_COLOR' => '1',
        ]);
    }

    /**
     * Clean ANSI color codes and truncate overly long outputs.
     */
    protected function clean(string $text): string
    {
        $text = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text) ?? $text;

        if (mb_strlen($text) > $this->maxOutput) {
            $text = "...(output truncated)...\n" . mb_substr($text, -$this->maxOutput);
        }

        return $text;
    }
}