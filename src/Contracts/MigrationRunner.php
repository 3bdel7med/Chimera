<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Contracts;

interface MigrationRunner
{
    /** Run only the given migration files. @param list<string> $paths */
    public function migrate(array $paths): string;

    /** Roll back only the given migration files. @param list<string> $paths */
    public function rollback(array $paths): string;
}
