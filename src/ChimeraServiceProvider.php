<?php

namespace Abdelhmed\Chimera;

use Abdelhmed\Chimera\Console\MorphCommand;
use Abdelhmed\Chimera\Console\RollbackCommand;
use Abdelhmed\Chimera\Console\TravelCommand;
use Illuminate\Support\ServiceProvider;

class ChimeraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chimera.php', 'chimera');
    }

    public function boot(): void
{
    if ($this->app->runningInConsole()) {
        $this->publishes([
            __DIR__.'/../config/chimera.php' => config_path('chimera.php'),
        ], 'chimera-config');

        $this->commands(
            MorphCommand::class,
            RollbackCommand::class,
            TravelCommand::class

        );
    }
}
}