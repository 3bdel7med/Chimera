<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Console;

use Closure;

/** Draws file paths as a folder tree. Single-child folder chains are merged (app/Http/Controllers/), like in VS Code. */
final class TreeRenderer
{
    /**
     * @param  array<string, string>  $leaves  path => the text shown for that file
     * @param  (Closure(string): string)|null  $styleDirectory
     * @return list<string>
     */
    public function render(array $leaves, ?Closure $styleDirectory = null): array
    {
        $style = $styleDirectory ?? fn (string $name): string => $name;
        $lines = [];
        $tree = $this->build($leaves);

        foreach ($this->ordered($tree) as $name) {
            $child = $tree[$name];

            if (! is_array($child)) {
                $lines[] = $child;

                continue;
            }

            [$label, $child] = $this->compress($name, $child);
            $lines[] = $style($label.'/');
            $this->children($child, '', $lines, $style);
        }

        return $lines;
    }

    /**
     * @param  array<string, string>  $leaves
     * @return array<string, mixed>
     */
    private function build(array $leaves): array
    {
        $tree = [];

        foreach ($leaves as $path => $text) {
            $segments = explode('/', $path);
            $file = array_pop($segments);
            $node = &$tree;

            foreach ($segments as $segment) {
                if (isset($node[$segment]) && ! is_array($node[$segment])) {
                    continue 2;
                }

                $node[$segment] ??= [];
                $node = &$node[$segment];
            }

            $node[$file] = $text;
            unset($node);
        }

        return $tree;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $lines
     */
    private function children(array $node, string $prefix, array &$lines, Closure $style): void
    {
        $names = $this->ordered($node);

        foreach ($names as $index => $name) {
            $last = $index === array_key_last($names);
            $branch = $last ? '└─ ' : '├─ ';
            $child = $node[$name];

            if (! is_array($child)) {
                $lines[] = $prefix.$branch.$child;

                continue;
            }

            [$label, $child] = $this->compress($name, $child);
            $lines[] = $prefix.$branch.$style($label.'/');
            $this->children($child, $prefix.($last ? '   ' : '│  '), $lines, $style);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function compress(string $name, array $node): array
    {
        while (count($node) === 1 && is_array(reset($node))) {
            $name .= '/'.key($node);
            $node = reset($node);
        }

        return [$name, $node];
    }

    /**
     * Folders first, then files, each group alphabetical.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function ordered(array $node): array
    {
        $names = array_map('strval', array_keys($node));
        usort($names, fn (string $a, string $b) => [is_array($node[$a]) ? 0 : 1, $a] <=> [is_array($node[$b]) ? 0 : 1, $b]);

        return $names;
    }
}
