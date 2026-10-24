<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Generation;

use Abdelhmed\Chimera\Analysis\ClassSummarizer;
use Abdelhmed\Chimera\Contracts\ProjectFiles;
use Abdelhmed\Chimera\Conventions\ConventionPrompt;
use Abdelhmed\Chimera\Data\Blueprint;
use Abdelhmed\Chimera\Data\FileSpec;
use Abdelhmed\Chimera\Data\GeneratedFile;
use Abdelhmed\Chimera\Enums\FileAction;
use Abdelhmed\Chimera\Enums\FileType;

/** Assembles the user-side prompt for one generation batch (or one healing pass). */
final class BatchPromptBuilder
{
    private const MAX_FILE_CHARS = 60000;

    public function __construct(
        private readonly ProjectFiles $files,
        private readonly ClassSummarizer $summarizer,
    ) {
    }

    /**
     * @param  array<string, mixed>  $projectContext
     * @param  list<FileSpec>  $batch
     * @param  list<GeneratedFile>  $done
     */
    public function build(string $prompt, Blueprint $blueprint, array $projectContext, array $batch, array $done, ?string $feedback): string
    {
        [$rules, $projectContext] = ConventionPrompt::split($projectContext);

        $sections = array_values(array_filter([
            "## Feature request\n{$prompt}",
            ConventionPrompt::section($rules),
            "## Blueprint: every file of this feature\n".$this->specLines($blueprint->files),
            "## Existing project summary (JSON)\n".$this->json($projectContext),
        ]));

        if ($done !== []) {
            $sections[] = "## Already generated in this run (signatures only, keep names consistent with these)\n"
                .$this->json(array_map(fn (GeneratedFile $f) => $this->digest($f), $done));
        }

        if (($existing = $this->existingContents($batch)) !== '') {
            $sections[] = "## Current contents of the existing files you must change\n{$existing}";
        }

        $sections[] = "## Generate exactly these files now\n".$this->specLines($batch);

        if ($feedback !== null) {
            $sections[] = "## Problems with your previous answer. Fix all of them\n{$feedback}";
        }

        return implode("\n\n", $sections);
    }

    /** @param array<string, string> $current path => current file content */
    public function buildHeal(string $prompt, Blueprint $blueprint, array $current, string $testOutput, ?string $feedback): string
    {
        $sections = [
            "## Feature request\n{$prompt}",
            "## Blueprint\n".$this->specLines($blueprint->files),
            "## Failing test output\n{$testOutput}",
            "## Current contents of the generated files\n".$this->fenced($current),
        ];

        if ($feedback !== null) {
            $sections[] = "## Problems with your previous answer. Fix all of them\n{$feedback}";
        }

        return implode("\n\n", $sections);
    }

    /** @param list<FileSpec> $specs */
    private function specLines(array $specs): string
    {
        return implode("\n", array_map(
            fn (FileSpec $s) => "- [{$s->action->value}] {$s->path} ({$s->type->value})".($s->description !== '' ? ": {$s->description}" : ''),
            $specs,
        ));
    }

    /** @return array<string, mixed> */
    private function digest(GeneratedFile $file): array
    {
        $digest = ['path' => $file->path(), 'type' => $file->type()->value];

        if ($file->type() === FileType::Migration) {
            preg_match_all('/\$table->(\w+)\(\s*[\'"](\w+)[\'"]/', $file->content, $m, PREG_SET_ORDER);

            return $digest + ['columns' => array_map(fn (array $c) => "{$c[2]}:{$c[1]}", $m)];
        }

        if ($file->spec->isPhp() && ($summary = $this->summarizer->summarize($file->content)) !== null) {
            return $digest + $summary;
        }

        return $digest;
    }

    /** @param list<FileSpec> $batch */
    private function existingContents(array $batch): string
    {
        $files = [];

        foreach ($batch as $spec) {
            if ($spec->action !== FileAction::Create && $this->files->exists($spec->path)) {
                $files[$spec->path] = $this->files->get($spec->path);
            }
        }

        return $this->fenced($files);
    }

    /** @param array<string, string> $files */
    private function fenced(array $files): string
    {
        $blocks = [];

        foreach ($files as $path => $content) {
            if (strlen($content) > self::MAX_FILE_CHARS) {
                $content = substr($content, 0, self::MAX_FILE_CHARS)."\n/* ...truncated... */";
            }

            $blocks[] = "### {$path}\n```\n{$content}\n```";
        }

        return implode("\n\n", $blocks);
    }

    private function json(mixed $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
