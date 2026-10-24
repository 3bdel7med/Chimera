<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Trust;

final class MutantResult
{
    public function __construct(
        public readonly Mutation $mutation,
        public readonly MutantStatus $status,
    ) {
    }

    public function label(): string
    {
        return "{$this->mutation->path}:{$this->mutation->line} {$this->mutation->description}";
    }
}
