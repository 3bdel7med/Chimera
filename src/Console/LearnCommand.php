<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Console;

use Abdelhmed\Chimera\Contracts\ConventionStore;
use Abdelhmed\Chimera\Conventions\Convention;
use Abdelhmed\Chimera\Conventions\ConventionLearner;
use Abdelhmed\Chimera\Conventions\ConventionSet;
use Illuminate\Console\Command;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\spin;

class LearnCommand extends Command
{
    protected $signature = 'chimera:learn
        {--show : Only print what was learned; do not save}
        {--yes : Save everything that was learned without asking}';

    protected $description = 'Learn how your project is written (through the AST) so generated code matches it';

    public function handle(ConventionLearner $learner, ConventionStore $store): int
    {
        $previous = $store->load();
        $set = spin(fn () => $learner->learn($previous), 'Reading your code...');

        if ($set->isEmpty()) {
            $this->components->info('No clear conventions found yet. Chimera needs a few controllers, models or tests to learn from.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Learned %d conventions from %d files.', count($set->conventions), $set->filesAnalysed));
        $this->table(['Convention', 'Confidence', 'Evidence', 'Now'], array_map(fn (Convention $c) => [
            $c->id,
            round($c->confidence * 100).'%',
            $c->evidence,
            $c->enabled ? 'on' : 'off',
        ], $set->conventions));

        if ($this->option('show')) {
            return self::SUCCESS;
        }

        if (! $this->option('yes')) {
            $set = $this->choose($set);
        }

        $store->save($set);
        $this->components->info(sprintf('Saved %d active conventions to %s. Commit this file so your team shares it; edit it to add your own rules.', count($set->enabled()), config('chimera.conventions.path')));

        return self::SUCCESS;
    }

    private function choose(ConventionSet $set): ConventionSet
    {
        $options = [];
        foreach ($set->conventions as $convention) {
            $options[$convention->id] = $convention->rule;
        }

        $selected = multiselect(
            label: 'Which conventions should Chimera follow?',
            options: $options,
            default: $set->enabledIds(),
            scroll: 12,
            hint: 'Space to toggle, Enter to save',
        );

        return new ConventionSet(
            array_map(fn (Convention $c) => $c->withEnabled(in_array($c->id, $selected, true)), $set->conventions),
            $set->filesAnalysed,
        );
    }
}
