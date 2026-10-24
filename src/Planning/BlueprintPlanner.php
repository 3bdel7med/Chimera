<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Planning;

use Abdelhmed\Chimera\Contracts\LlmClient;
use Abdelhmed\Chimera\Conventions\ConventionPrompt;
use Abdelhmed\Chimera\Data\Blueprint;
use Abdelhmed\Chimera\Support\PromptRepository;

/** Phase 1: ask the model which files the feature needs (no code yet). */
final class BlueprintPlanner
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly PromptRepository $prompts,
        private readonly BlueprintParser $parser,
        private readonly BlueprintNormalizer $normalizer,
        private readonly int $maxFiles = 40,
    ) {
    }

    /** @param array<string, mixed> $projectContext */
    public function plan(string $prompt, array $projectContext): Blueprint
    {
        $system = $this->prompts->render('blueprint.system', ['max_files' => $this->maxFiles]);

        [$rules, $projectContext] = ConventionPrompt::split($projectContext);

        $user = "## Feature request\n{$prompt}\n\n"
            .(($conventions = ConventionPrompt::section($rules)) !== null ? "{$conventions}\n\n" : '')
            ."## Existing project summary (JSON)\n"
            .json_encode($projectContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $raw = $this->llm->generateJson($system, $user, BlueprintSchema::definition());

        return $this->normalizer->normalize($this->parser->parse($raw));
    }
}
