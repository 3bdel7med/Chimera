<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Application;

use Abdelhmed\Chimera\Contracts\CheckpointStore;
use Abdelhmed\Chimera\Contracts\ProjectFiles;
use Abdelhmed\Chimera\Data\ExecutionReport;
use Abdelhmed\Chimera\Data\GeneratedFile;
use Abdelhmed\Chimera\Enums\FileAction;
use Abdelhmed\Chimera\Exceptions\ChimeraException;
use Abdelhmed\Chimera\Injection\InjectorRegistry;
use Abdelhmed\Chimera\Support\PathGuard;

/** Writes generated files to disk. Every file is backed up in the checkpoint first, so any run can be rolled back. */
final class ChangeApplier
{
    public function __construct(
        private readonly ProjectFiles $files,
        private readonly PathGuard $guard,
        private readonly InjectorRegistry $injectors,
        private readonly CheckpointStore $checkpoints,
    ) {
    }

    /** @param list<GeneratedFile> $generated */
    public function apply(string $checkpointId, array $generated): ExecutionReport
    {
        $report = new ExecutionReport();

        foreach ($generated as $file) {
            $path = $this->guard->normalize($file->path());
            $existing = $this->files->exists($path) ? $this->files->get($path) : null;

            $this->checkpoints->recordOriginal($checkpointId, $path, $existing);

            $new = $this->resolveContent($file, $path, $existing);

            if ($existing !== null && $new === $existing) {
                $report->skipped[$path] = 'no changes';

                continue;
            }

            $this->files->put($path, rtrim($new)."\n");

            match (true) {
                $existing === null => $report->created[] = $path,
                $file->action() === FileAction::Inject => $report->injected[] = $path,
                default => $report->modified[] = $path,
            };
        }

        return $report;
    }

    private function resolveContent(GeneratedFile $file, string $path, ?string $existing): string
    {
        if ($file->action() !== FileAction::Inject) {
            return $file->content;
        }

        if ($existing === null) {
            throw new ChimeraException("Cannot inject into [{$path}] because it does not exist.");
        }

        return $this->injectors->for($file->spec)->inject($existing, $file->content);
    }
}
