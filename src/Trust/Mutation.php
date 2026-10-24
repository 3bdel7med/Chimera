<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Trust;

/** One deliberate bug: the whole mutated file plus a human description of what was changed. */
final class Mutation
{
    public function __construct(
        public readonly string $path,
        public readonly string $operator,
        public readonly string $description,
        public readonly int $line,
        public readonly string $code,
    ) {
    }
}
