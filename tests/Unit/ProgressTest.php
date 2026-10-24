<?php

use Abdelhmed\Chimera\Console\ConsoleProgress;
use Abdelhmed\Chimera\Console\TreeRenderer;
use Abdelhmed\Chimera\Data\FileSpec;
use Abdelhmed\Chimera\Enums\FileAction;
use Abdelhmed\Chimera\Enums\FileState;
use Abdelhmed\Chimera\Enums\FileType;
use Abdelhmed\Chimera\Enums\Phase;

it('draws a folder tree with merged single-child folders and folders first', function () {
    $lines = (new TreeRenderer())->render([
        'routes/api.php' => 'api.php',
        'app/Http/Controllers/PostController.php' => 'PostController.php',
        'app/Models/Post.php' => 'Post.php',
        'app/Models/User.php' => 'User.php',
        'app/helpers.php' => 'helpers.php',
    ]);

    expect($lines)->toBe([
        'app/',
        '├─ Http/Controllers/',
        '│  └─ PostController.php',
        '├─ Models/',
        '│  ├─ Post.php',
        '│  └─ User.php',
        '└─ helpers.php',
        'routes/',
        '└─ api.php',
    ]);
});

it('tells the story of a run line by line and finishes with the file tree', function () {
    $screen = '';
    $time = 0.0;
    $progress = new ConsoleProgress(function (string $t) use (&$screen) { $screen .= $t; }, false, function () use (&$time) { return $time; });

    $progress->phase(Phase::Scan, 'reading your project');
    $time = 0.4;
    $progress->phaseDone(Phase::Scan, '14 files analysed with the AST');

    $progress->plan([
        new FileSpec('app/Models/Post.php', FileType::Model, FileAction::Create, 'Post model'),
        new FileSpec('routes/api.php', FileType::Route, FileAction::Inject, 'posts routes'),
    ]);
    $progress->file('app/Models/Post.php', FileState::Written, 'created');
    $progress->file('routes/api.php', FileState::Written, 'injected (AST)');
    $progress->file('routes/api.php', FileState::Healed);
    $progress->summary();

    expect($screen)->toContain("▸ Scanning your project  reading your project\n");
    expect($screen)->toContain("✔ Scanning your project  14 files analysed with the AST  (0.4s)\n");
    expect($screen)->toContain('○ Post.php  model · CREATE — Post model');   // the plan tree
    expect($screen)->toContain('✔ Post.php  created');                         // the summary tree
    expect($screen)->toContain('✔ api.php  injected (AST) · fixed by the AI');
    expect($screen)->toContain('  ✔ written   app/Models/Post.php  created');
});

it('only draws throw-away status lines on a real terminal, and cleans them up', function () {
    $plain = '';
    (new ConsoleProgress(function (string $t) use (&$plain) { $plain .= $t; }, false))->detail('analysing app/Models/User.php');
    expect($plain)->toBe('');

    $tty = '';
    $progress = new ConsoleProgress(function (string $t) use (&$tty) { $tty .= $t; }, true, fn () => 0.0);
    $progress->detail('analysing app/Models/User.php');
    $progress->phaseDone(Phase::Scan, 'done');

    expect($tty)->toContain("\r\033[K");
    expect(substr_count($tty, "\r\033[K"))->toBe(2); // one to draw the status, one to wipe it before the permanent line
});
