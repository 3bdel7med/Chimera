<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Planning;

use Abdelhmed\Chimera\Contracts\ProjectFiles;
use Abdelhmed\Chimera\Data\Blueprint;
use Abdelhmed\Chimera\Data\FileSpec;
use Abdelhmed\Chimera\Enums\FileAction;
use Abdelhmed\Chimera\Enums\FileType;
use Abdelhmed\Chimera\Exceptions\GenerationException;
use Abdelhmed\Chimera\Exceptions\UnsafePathException;
use Abdelhmed\Chimera\Support\PathGuard;
use Closure;
use DateTimeImmutable;

/**
 * Makes the AI's plan safe and consistent with the real project before anything is shown or written:
 * drops unsafe paths, aligns actions with what actually exists, stamps migrations and orders files by dependency.
 */
final class BlueprintNormalizer
{
    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;

    public function __construct(
        private readonly PathGuard $guard,
        private readonly ProjectFiles $files,
        private readonly int $maxFiles = 40,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    public function normalize(Blueprint $blueprint): Blueprint
    {
        $warnings = [];
        $specs = [];
        $seen = [];

        foreach ($blueprint->files as $spec) {
            try {
                $path = $this->guard->normalize($spec->path);
            } catch (UnsafePathException $e) {
                $warnings[] = 'Skipped an unsafe file proposed by the AI: '.$e->getMessage();

                continue;
            }

            if (! isset($seen[$path])) {
                $seen[$path] = true;
                $specs[] = $spec->with(path: $path);
            }
        }

        if ($specs === []) {
            throw new GenerationException('The blueprint contained no files that are safe to write.');
        }

        $reconciled = [];
        foreach ($this->stampMigrations($specs) as $spec) {
            $reconciled[] = $this->reconcile($spec, $warnings);
        }
        $specs = $reconciled;
        usort($specs, fn (FileSpec $a, FileSpec $b) => $a->type->order() <=> $b->type->order());

        if (count($specs) > $this->maxFiles) {
            throw new GenerationException(sprintf(
                'The blueprint has %d files, above the limit of %d. Ask for a smaller feature or raise generation.max_files.',
                count($specs),
                $this->maxFiles,
            ));
        }

        return $blueprint->with($specs, $warnings);
    }

    /** @param list<string> $warnings */
    private function reconcile(FileSpec $spec, array &$warnings): FileSpec
    {
        $exists = $this->files->exists($spec->path);
        $action = $spec->action;

        if ($action === FileAction::Create && $exists) {
            $action = FileAction::Modify;
            $warnings[] = "{$spec->path} already exists and will be replaced (a backup is kept for rollback).";
        } elseif ($action !== FileAction::Create && ! $exists) {
            $action = FileAction::Create;
        }

        if ($action === FileAction::Inject && ! $spec->isPhp()) {
            $action = FileAction::Modify;
        }

        if ($spec->type === FileType::Route && $action === FileAction::Create) {
            $warnings[] = "{$spec->path} does not exist yet; make sure the application loads it (Laravel 11+: php artisan install:api for routes/api.php).";
        }

        if ($spec->type === FileType::Migration && $action !== FileAction::Create) {
            $warnings[] = "{$spec->path} is an existing migration being rewritten; a new migration is usually safer.";
        }

        return $action === $spec->action ? $spec : $spec->with(action: $action);
    }

    /**
     * The AI cannot know the current time, so every new migration gets a fresh, strictly increasing timestamp.
     *
     * @param  list<FileSpec>  $specs
     * @return list<FileSpec>
     */
    private function stampMigrations(array $specs): array
    {
        $now = ($this->now)();
        $offset = 0;

        return array_map(function (FileSpec $spec) use ($now, &$offset): FileSpec {
            if ($spec->type !== FileType::Migration || $spec->action !== FileAction::Create) {
                return $spec;
            }

            $name = (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($spec->path));
            $name = str_ends_with($name, '.php') ? $name : $name.'.php';
            $stamp = $now->modify('+'.$offset++.' seconds')->format('Y_m_d_His');

            return $spec->with(path: "database/migrations/{$stamp}_{$name}");
        }, $specs);
    }
}
