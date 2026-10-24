<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Generation;

use Abdelhmed\Chimera\Analysis\PhpSyntax;
use Abdelhmed\Chimera\Contracts\ProjectFiles;
use Abdelhmed\Chimera\Data\GeneratedFile;
use Abdelhmed\Chimera\Enums\FileAction;
use Abdelhmed\Chimera\Injection\InjectorRegistry;
use Throwable;

/** Catches broken PHP before it is written: syntax errors, and snippets that cannot be injected. */
final class GeneratedFileValidator
{
    public function __construct(
        private readonly ProjectFiles $files,
        private readonly InjectorRegistry $injectors,
    ) {
    }

    /**
     * @param  list<GeneratedFile>  $generated
     * @return array<string, string> path => problem
     */
    public function validate(array $generated): array
    {
        $errors = [];

        foreach ($generated as $file) {
            if (trim($file->content) === '') {
                $errors[$file->path()] = 'The file is empty.';

                continue;
            }

            if (! $file->spec->isPhp()) {
                continue;
            }

            $error = $file->action() === FileAction::Inject
                ? $this->injectionError($file)
                : PhpSyntax::error($file->content);

            if ($error !== null) {
                $errors[$file->path()] = $error;
            }
        }

        return $errors;
    }

    private function injectionError(GeneratedFile $file): ?string
    {
        if (! $this->files->exists($file->path())) {
            return 'Cannot inject into a file that does not exist.';
        }

        try {
            $this->injectors->for($file->spec)->inject($this->files->get($file->path()), $file->content);
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }
}
