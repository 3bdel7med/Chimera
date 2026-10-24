<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Planning;

use Abdelhmed\Chimera\Data\Blueprint;
use Abdelhmed\Chimera\Data\FileSpec;
use Abdelhmed\Chimera\Enums\FileAction;
use Abdelhmed\Chimera\Enums\FileType;
use Abdelhmed\Chimera\Exceptions\InvalidAiResponseException;

/** Turns the model's loosely-shaped JSON into a typed Blueprint. */
final class BlueprintParser
{
    /** @param array<string, mixed> $data */
    public function parse(array $data): Blueprint
    {
        $impact = is_array($data['impact'] ?? null) ? $data['impact'] : (is_array($data['impactAnalysis'] ?? null) ? $data['impactAnalysis'] : []);
        $files = [];
        $seen = [];

        foreach ((array) ($data['files'] ?? []) as $item) {
            $item = is_string($item) ? ['path' => $item] : $item;

            if (! is_array($item)) {
                continue;
            }

            $path = str_replace('\\', '/', trim($this->text($item['path'] ?? $item['filePath'] ?? $item['file'] ?? '')));

            if ($path === '' || isset($seen[$path])) {
                continue;
            }

            $seen[$path] = true;
            $guessed = FileType::guessFromPath($path);
            // The location decides the type when it is unambiguous, because it also decides how the file is injected.
            $type = $guessed !== FileType::Other ? $guessed : (FileType::fromLoose($item['type'] ?? null) ?? FileType::Other);

            $files[] = new FileSpec($path, $type, FileAction::fromLoose($item['action'] ?? null), trim($this->text($item['description'] ?? '')));
        }

        if ($files === []) {
            throw new InvalidAiResponseException('The AI returned a blueprint without any files.');
        }

        return new Blueprint(
            summary: trim($this->text($data['summary'] ?? '')),
            files: $files,
            hasBreakingChanges: (bool) ($impact['hasBreakingChanges'] ?? $impact['has_breaking_changes'] ?? false),
            warnings: $this->strings($impact['warnings'] ?? []),
            composerPackages: $this->strings($data['composerPackages'] ?? []),
            envVariables: $this->strings($data['envVariables'] ?? []),
        );
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return array_values(array_filter(array_map(fn (mixed $v) => trim($this->text($v)), (array) $value), fn (string $v) => $v !== ''));
    }
}
