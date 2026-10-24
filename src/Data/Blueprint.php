<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Data;

use Abdelhmed\Chimera\Enums\FileType;

/** The AI's plan: which files to touch and why. Contains no code. */
final class Blueprint
{
    /**
     * @param  list<FileSpec>  $files
     * @param  list<string>  $warnings
     * @param  list<string>  $composerPackages
     * @param  list<string>  $envVariables
     */
    public function __construct(
        public readonly string $summary,
        public readonly array $files,
        public readonly bool $hasBreakingChanges = false,
        public readonly array $warnings = [],
        public readonly array $composerPackages = [],
        public readonly array $envVariables = [],
    ) {
    }

    /**
     * @param  list<FileSpec>  $files
     * @param  list<string>  $extraWarnings
     */
    public function with(array $files, array $extraWarnings = []): self
    {
        return new self(
            $this->summary,
            $files,
            $this->hasBreakingChanges,
            [...$this->warnings, ...$extraWarnings],
            $this->composerPackages,
            $this->envVariables,
        );
    }

    /** @return list<FileSpec> */
    public function filesOfType(FileType $type): array
    {
        return array_values(array_filter($this->files, fn (FileSpec $f) => $f->type === $type));
    }
}
