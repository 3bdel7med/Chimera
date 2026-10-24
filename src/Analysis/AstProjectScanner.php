<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Analysis;

use Abdelhmed\Chimera\Contracts\ProjectFiles;
use Abdelhmed\Chimera\Contracts\ProjectScanner;
use Closure;

/**
 * Builds the context sent to the model. It only reads PHP source from known directories and
 * composer.json package names, so .env and other secrets can never leak into a prompt.
 */
final class AstProjectScanner implements ProjectScanner
{
    /** @param array{max_models?: int, max_controllers?: int, max_routes_per_file?: int} $limits */
    public function __construct(
        private readonly ProjectFiles $files,
        private readonly ClassSummarizer $summarizer,
        private readonly RouteSummarizer $routes,
        private readonly array $limits = [],
    ) {
    }

    public function scan(?Closure $onFile = null): array
    {
        return array_filter([
            'composer_packages' => $this->composerPackages(),
            'test_framework' => $this->testFramework(),
            'models' => $this->models($onFile),
            'controllers' => $this->controllers($onFile),
            'routes' => $this->routes($onFile),
            'database' => $this->database($onFile),
        ], fn (mixed $value) => $value !== [] && $value !== null);
    }

    /** @return list<string> */
    private function composerPackages(): array
    {
        if (! $this->files->exists('composer.json')) {
            return [];
        }

        $json = json_decode($this->files->get('composer.json'), true);
        $names = array_keys(array_merge((array) ($json['require'] ?? []), (array) ($json['require-dev'] ?? [])));

        return array_values(array_filter($names, fn (string $name) => $name !== 'php' && ! str_starts_with($name, 'ext-')));
    }

    private function testFramework(): string
    {
        return match (true) {
            $this->files->exists('vendor/bin/pest') => 'pest',
            $this->files->exists('vendor/bin/phpunit') => 'phpunit',
            default => 'unknown',
        };
    }

    /** @return list<array<string, mixed>> */
    private function models(?Closure $onFile): array
    {
        $paths = $this->files->list('app/Models');

        if ($paths === []) {
            // Older apps keep models directly in app/.
            $paths = array_values(array_filter($this->files->list('app'), fn (string $p) => substr_count($p, '/') === 1));
        }

        $models = [];
        foreach (array_slice($paths, 0, $this->limits['max_models'] ?? 60) as $path) {
            $onFile?->__invoke($path);

            if (($summary = $this->summarizer->summarize($this->files->get($path), false)) !== null) {
                $models[] = ['file' => $path] + $summary;
            }
        }

        return $models;
    }

    /** @return list<array<string, mixed>> */
    private function controllers(?Closure $onFile): array
    {
        $controllers = [];

        foreach (array_slice($this->files->list('app/Http/Controllers'), 0, $this->limits['max_controllers'] ?? 60) as $path) {
            $onFile?->__invoke($path);
            $summary = $this->summarizer->summarize($this->files->get($path));

            if ($summary === null) {
                continue;
            }

            $controllers[] = [
                'file' => $path,
                'class' => $summary['class'],
                'methods' => array_map(fn (string $sig) => strstr($sig, '(', true) ?: $sig, $summary['methods'] ?? []),
            ];
        }

        return $controllers;
    }

    /** @return array<string, list<string>> */
    private function routes(?Closure $onFile): array
    {
        $routes = [];

        foreach ($this->files->list('routes') as $path) {
            $onFile?->__invoke($path);
            $lines = $this->routes->summarize($this->files->get($path), $this->limits['max_routes_per_file'] ?? 80);

            if ($lines !== []) {
                $routes[$path] = $lines;
            }
        }

        return $routes;
    }

    /** @return array<string, mixed> */
    private function database(?Closure $onFile): array
    {
        $migrations = $this->files->list('database/migrations');
        $tables = [];

        foreach ($migrations as $path) {
            $onFile?->__invoke($path);

            if (preg_match_all('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]/', $this->files->get($path), $m) > 0) {
                array_push($tables, ...$m[1]);
            }
        }

        if ($migrations === []) {
            return [];
        }

        $tables = array_values(array_unique($tables));
        sort($tables);

        return [
            'migration_count' => count($migrations),
            'tables' => $tables,
            'latest_migrations' => array_map('basename', array_slice($migrations, -5)),
        ];
    }
}
