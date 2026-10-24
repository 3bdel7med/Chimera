<?php

use Abdelhmed\Chimera\Exceptions\UnsafePathException;
use Abdelhmed\Chimera\Support\PathGuard;

function guard(): PathGuard
{
    return new PathGuard(['app', 'routes', 'database', 'tests', 'resources', 'config']);
}

it('accepts normal Laravel paths and normalises them', function () {
    expect(guard()->normalize('app/Models/Post.php'))->toBe('app/Models/Post.php');
    expect(guard()->normalize('./app//Models\\Post.php'))->toBe('app/Models/Post.php');
});

it('rejects traversal, absolute paths, env files and protected folders', function (string $path) {
    expect(fn () => guard()->normalize($path))->toThrow(UnsafePathException::class);
})->with([
    '../.env',
    'app/../../etc/passwd',
    '/etc/passwd',
    'C:/Windows/win.ini',
    '.env',
    'app/.env.backup',
    'vendor/autoload.php',
    'storage/logs/laravel.log',
    'composer.json',
    'public/index.php',
    'app',
]);
