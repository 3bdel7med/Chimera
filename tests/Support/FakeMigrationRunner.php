<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Tests\Support;

use Abdelhmed\Chimera\Contracts\MigrationRunner;

final class FakeMigrationRunner implements MigrationRunner
{
    /** @var list<list<string>> */
    public array $migrated = [];

    /** @var list<list<string>> */
    public array $rolledBack = [];

    public function migrate(array $paths): string
    {
        $this->migrated[] = $paths;

        return 'ok';
    }

    public function rollback(array $paths): string
    {
        $this->rolledBack[] = $paths;

        return 'ok';
    }
}
