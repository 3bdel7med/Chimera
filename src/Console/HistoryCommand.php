<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Console;

use Abdelhmed\Chimera\Contracts\CheckpointStore;
use Illuminate\Console\Command;

class HistoryCommand extends Command
{
    protected $signature = 'chimera:history {id? : Show the files touched by one checkpoint}';

    protected $description = 'List Chimera runs that can be rolled back';

    public function handle(CheckpointStore $checkpoints): int
    {
        if ($id = $this->argument('id')) {
            $checkpoint = $checkpoints->find((string) $id);

            if ($checkpoint === null) {
                $this->components->error("Unknown checkpoint [{$id}].");

                return self::FAILURE;
            }

            $this->line("\"{$checkpoint->prompt}\"");
            foreach ($checkpoint->created as $path) {
                $this->line("  + {$path}");
            }
            foreach ($checkpoint->modified as $path) {
                $this->line("  ~ {$path}");
            }
            foreach ($checkpoint->migrations as $path) {
                $this->line("  migrated {$path}");
            }

            return self::SUCCESS;
        }

        $all = $checkpoints->all();

        if ($all === []) {
            $this->components->info('No Chimera runs yet.');

            return self::SUCCESS;
        }

        $this->table(['Id', 'When', 'Prompt', 'New', 'Changed', 'Status'], array_map(fn ($c) => [
            $c->id,
            $c->createdAt,
            mb_strimwidth($c->prompt, 0, 50, '…'),
            count($c->created),
            count($c->modified),
            $c->rolledBackAt === null ? 'active' : 'rolled back',
        ], $all));

        return self::SUCCESS;
    }
}
