<?php

use Abdelhmed\Chimera\Data\Blueprint;
use Abdelhmed\Chimera\Enums\FileAction;
use Abdelhmed\Chimera\Enums\FileType;
use Abdelhmed\Chimera\Exceptions\GenerationException;
use Abdelhmed\Chimera\Exceptions\InvalidAiResponseException;
use Abdelhmed\Chimera\Planning\BlueprintNormalizer;
use Abdelhmed\Chimera\Planning\BlueprintParser;
use Abdelhmed\Chimera\Support\PathGuard;
use Abdelhmed\Chimera\Tests\Support\InMemoryProjectFiles;

function normalizer(array $files = [], int $max = 40): BlueprintNormalizer
{
    return new BlueprintNormalizer(
        new PathGuard(['app', 'routes', 'database', 'tests']),
        new InMemoryProjectFiles($files),
        $max,
        fn () => new DateTimeImmutable('2026-10-23 10:00:00'),
    );
}

it('parses a loosely shaped AI blueprint', function () {
    $blueprint = (new BlueprintParser())->parse([
        'summary' => 'Posts API',
        'impact' => ['hasBreakingChanges' => true, 'warnings' => ['careful']],
        'composerPackages' => ['spatie/laravel-data'],
        'files' => [
            ['filePath' => 'app/Models/Post.php', 'type' => 'Eloquent Model', 'action' => 'create', 'description' => 'Post'],
            ['path' => 'app/Models/Post.php', 'type' => 'model', 'action' => 'CREATE'],
            'routes/api.php',
            ['path' => 'app/Actions/Publish.php', 'type' => 'service', 'action' => 'weird'],
        ],
    ]);

    expect($blueprint->files)->toHaveCount(3);
    expect($blueprint->files[0]->type)->toBe(FileType::Model);
    expect($blueprint->files[1]->type)->toBe(FileType::Route);
    expect($blueprint->files[2]->type)->toBe(FileType::Service);
    expect($blueprint->files[2]->action)->toBe(FileAction::Create);
    expect($blueprint->hasBreakingChanges)->toBeTrue();
    expect($blueprint->composerPackages)->toBe(['spatie/laravel-data']);
});

it('rejects a blueprint without files', function () {
    expect(fn () => (new BlueprintParser())->parse(['summary' => 'x', 'files' => []]))->toThrow(InvalidAiResponseException::class);
});

it('drops unsafe paths, stamps migrations, reconciles actions and orders by dependency', function () {
    $blueprint = (new BlueprintParser())->parse(['summary' => 's', 'files' => [
        ['path' => 'tests/Feature/PostTest.php', 'type' => 'test', 'action' => 'CREATE', 'description' => ''],
        ['path' => 'app/Http/Controllers/PostController.php', 'type' => 'controller', 'action' => 'CREATE', 'description' => ''],
        ['path' => 'routes/api.php', 'type' => 'route', 'action' => 'INJECT', 'description' => ''],
        ['path' => 'app/Models/User.php', 'type' => 'model', 'action' => 'CREATE', 'description' => ''],
        ['path' => 'database/migrations/2020_01_01_000000_create_posts_table.php', 'type' => 'migration', 'action' => 'CREATE', 'description' => ''],
        ['path' => 'database/migrations/create_comments_table', 'type' => 'migration', 'action' => 'CREATE', 'description' => ''],
        ['path' => '../.env', 'type' => 'other', 'action' => 'MODIFY', 'description' => ''],
    ]]);

    $result = normalizer(['app/Models/User.php' => '<?php class User {}'])->normalize($blueprint);
    $paths = array_map(fn ($f) => $f->path, $result->files);

    expect($paths)->toBe([
        'database/migrations/2026_10_23_100000_create_posts_table.php',
        'database/migrations/2026_10_23_100001_create_comments_table.php',
        'app/Models/User.php',
        'app/Http/Controllers/PostController.php',
        'routes/api.php',
        'tests/Feature/PostTest.php',
    ]);

    $byPath = [];
    foreach ($result->files as $f) {
        $byPath[$f->path] = $f;
    }

    expect($byPath['app/Models/User.php']->action)->toBe(FileAction::Modify);   // exists, so CREATE became MODIFY
    expect($byPath['routes/api.php']->action)->toBe(FileAction::Create);        // missing, so INJECT became CREATE
    expect(implode("\n", $result->warnings))->toContain('unsafe');
    expect(implode("\n", $result->warnings))->toContain('already exists');
});

it('refuses oversized blueprints', function () {
    $files = array_map(fn ($i) => ['path' => "app/Services/S{$i}.php", 'type' => 'service', 'action' => 'CREATE', 'description' => ''], range(1, 5));
    $blueprint = (new BlueprintParser())->parse(['summary' => 's', 'files' => $files]);

    expect(fn () => normalizer([], 3)->normalize($blueprint))->toThrow(GenerationException::class);
});
