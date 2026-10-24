<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions;

use Abdelhmed\Chimera\Contracts\ProjectFiles;
use Abdelhmed\Chimera\Conventions\Checks\ConventionCheck;
use Abdelhmed\Chimera\Data\GeneratedFile;
use Abdelhmed\Chimera\Enums\FileAction;
use PhpParser\ParserFactory;
use Throwable;

/** Verifies generated code against the conventions that can be checked mechanically. No AI involved. */
final class ConventionChecker
{
    /** @param list<ConventionCheck> $checks */
    public function __construct(
        private readonly array $checks,
        private readonly ProjectFiles $files,
    ) {
    }

    /** True when at least one enabled convention can be enforced. */
    public function canEnforce(ConventionSet $set): bool
    {
        return $this->activeChecks($set) !== [];
    }

    /**
     * @param  list<GeneratedFile>  $generated
     * @return list<Violation>
     */
    public function check(array $generated, ConventionSet $set): array
    {
        $checks = $this->activeChecks($set);

        if ($checks === []) {
            return [];
        }

        $models = $this->modelNames($generated);
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $violations = [];

        foreach ($generated as $file) {
            // INJECT snippets are fragments, not whole files, so they cannot be judged on their own.
            if (! $file->spec->isPhp() || $file->action() === FileAction::Inject) {
                continue;
            }

            try {
                $stmts = $parser->parse($file->content) ?? [];
            } catch (Throwable) {
                continue;
            }

            foreach ($checks as $check) {
                foreach ($check->violations($file->spec, $stmts, $models) as $message) {
                    $violations[] = new Violation($file->path(), $check->id(), $message);
                }
            }
        }

        return $violations;
    }

    /** @return list<ConventionCheck> */
    private function activeChecks(ConventionSet $set): array
    {
        $enabled = array_flip($set->enabledIds());

        return array_values(array_filter($this->checks, fn (ConventionCheck $c) => isset($enabled[$c->id()])));
    }

    /**
     * @param  list<GeneratedFile>  $generated
     * @return list<string>
     */
    private function modelNames(array $generated): array
    {
        $paths = $this->files->list('app/Models');

        foreach ($generated as $file) {
            if (str_starts_with($file->path(), 'app/Models/')) {
                $paths[] = $file->path();
            }
        }

        return array_values(array_unique(array_map(fn (string $p) => basename($p, '.php'), $paths)));
    }
}
