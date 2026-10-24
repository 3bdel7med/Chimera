<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Verification;

use Abdelhmed\Chimera\Application\ChangeApplier;
use Abdelhmed\Chimera\Contracts\Reporter;
use Abdelhmed\Chimera\Contracts\TestRunner;
use Abdelhmed\Chimera\Data\Blueprint;
use Abdelhmed\Chimera\Data\GeneratedFile;
use Abdelhmed\Chimera\Enums\FileType;
use Abdelhmed\Chimera\Exceptions\ChimeraException;
use Abdelhmed\Chimera\Generation\FileGenerator;

/** Runs the generated tests; when they fail, feeds the failure back to the model and applies its fixes. */
final class HealingService
{
    public function __construct(
        private readonly TestRunner $tests,
        private readonly FileGenerator $generator,
        private readonly ChangeApplier $applier,
        private readonly int $maxAttempts = 2,
    ) {
    }

    /** @param list<GeneratedFile> $generated */
    public function run(string $prompt, Blueprint $blueprint, array $generated, string $checkpointId, Reporter $reporter): HealingOutcome
    {
        $result = $reporter->spin('Running the generated tests...', fn () => $this->tests->run($this->testPaths($generated)));
        $attempts = 0;
        $healed = [];

        while ($result->hasFailed() && $attempts < $this->maxAttempts) {
            $attempts++;
            $reporter->warn("Tests failed. Asking the AI to fix them (attempt {$attempts}/{$this->maxAttempts})...");

            try {
                $fixes = $reporter->spin(
                    'Healing the generated code...',
                    fn () => $this->generator->heal($prompt, $blueprint, $generated, $result->output)
                );

                $this->applier->apply($checkpointId, $fixes);
            } catch (ChimeraException $e) {
                $reporter->error('Healing stopped: '.$e->getMessage());
                break;
            }

            $generated = $this->merge($generated, $fixes);
            $healed = array_values(array_unique([...$healed, ...array_map(fn (GeneratedFile $f) => $f->path(), $fixes)]));
            $result = $reporter->spin('Re-running the tests...', fn () => $this->tests->run($this->testPaths($generated)));
        }

        return new HealingOutcome($result, $generated, $attempts, $healed);
    }

    /**
     * @param  list<GeneratedFile>  $generated
     * @return list<string>
     */
    private function testPaths(array $generated): array
    {
        $paths = [];

        foreach ($generated as $file) {
            if ($file->type() === FileType::Test && $file->spec->isPhp()) {
                $paths[] = $file->path();
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  list<GeneratedFile>  $generated
     * @param  list<GeneratedFile>  $fixes
     * @return list<GeneratedFile>
     */
    private function merge(array $generated, array $fixes): array
    {
        $byPath = [];
        foreach ([...$generated, ...$fixes] as $file) {
            $byPath[$file->path()] = isset($byPath[$file->path()])
                ? new GeneratedFile($byPath[$file->path()]->spec, $file->content)
                : $file;
        }

        return array_values($byPath);
    }
}
