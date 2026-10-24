<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions;

final class Violation
{
    public function __construct(
        public readonly string $path,
        public readonly string $conventionId,
        public readonly string $message,
    ) {
    }
}
