<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Console;

use Abdelhmed\Chimera\Exceptions\ChimeraException;
use Abdelhmed\Chimera\Pipeline\MorphContext;
use Abdelhmed\Chimera\Pipeline\MorphPipeline;
use Illuminate\Console\Command;

use function Laravel\Prompts\text;

class MorphCommand extends Command
{
    protected $signature = 'chimera:morph
        {prompt? : Describe the feature in plain language}
        {--dry-run : Show the blueprint only; generate and write nothing}
        {--yes : Skip the confirmation prompt}
        {--no-migrate : Do not run the generated migrations}
        {--no-tests : Do not run the generated tests}
        {--no-trust : Skip the trust score (planting deliberate bugs to test the tests)}
        {--no-conventions : Ignore your learned conventions}';

    protected $description = 'Plan, generate and verify a feature from a natural-language description';

    public function handle(MorphPipeline $pipeline): int
    {
        if ($this->laravel->environment('production')) {
            $this->components->error('Chimera writes code and runs migrations, so it refuses to run in production.');

            return self::FAILURE;
        }

        $prompt = trim((string) ($this->argument('prompt') ?: text('What should Chimera build?', 'e.g. A task manager with projects and due dates')));

        if ($prompt === '') {
            $this->components->error('A feature description is required.');

            return self::FAILURE;
        }

        $context = new MorphContext(
            prompt: $prompt,
            dryRun: (bool) $this->option('dry-run'),
            assumeYes: (bool) $this->option('yes'),
            runMigrations: (bool) config('chimera.migrate', true) && ! $this->option('no-migrate'),
            runTests: (bool) config('chimera.testing.enabled', true) && ! $this->option('no-tests'),
            useConventions: (bool) config('chimera.conventions.enabled', true) && ! $this->option('no-conventions'),
            runTrust: (bool) config('chimera.trust.enabled', true) && ! $this->option('no-trust'),
        );

        try {
            $pipeline->run($context);
        } catch (ChimeraException $e) {
            $this->components->error($e->getMessage());

            if ($context->checkpointId !== null) {
                $this->line("Restore your project with: php artisan chimera:rollback {$context->checkpointId}");
            }

            return self::FAILURE;
        }

        if ($context->isHalted()) {
            $context->hasFailed() ? $this->components->error((string) $context->haltReason()) : $this->components->info((string) $context->haltReason());
        }

        return $context->hasFailed() ? self::FAILURE : self::SUCCESS;
    }
}
