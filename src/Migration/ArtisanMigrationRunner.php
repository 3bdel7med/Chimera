<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Migration;

use Abdelhmed\Chimera\Contracts\MigrationRunner;
use Illuminate\Contracts\Console\Kernel;

/** Runs only the given migration files, so unrelated pending migrations of the developer are never executed. */
final class ArtisanMigrationRunner implements MigrationRunner
{
    public function __construct(private readonly Kernel $artisan)
    {
    }

    public function migrate(array $paths): string
    {
        return $this->call('migrate', $paths);
    }

    public function rollback(array $paths): string
    {
        return $this->call('migrate:rollback', $paths);
    }

    /** @param list<string> $paths */
    private function call(string $command, array $paths): string
    {
        $this->artisan->call($command, ['--path' => $paths, '--force' => true]);

        return trim($this->artisan->output());
    }
}
