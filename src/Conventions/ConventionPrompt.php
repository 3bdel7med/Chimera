<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions;

/** The conventions travel inside the project context; prompts show them as their own section instead of JSON. */
final class ConventionPrompt
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{0: list<string>, 1: array<string, mixed>} the rules, and the context without them
     */
    public static function split(array $context): array
    {
        $rules = array_values(array_filter((array) ($context['conventions'] ?? []), 'is_string'));
        unset($context['conventions']);

        return [$rules, $context];
    }

    /** @param list<string> $rules */
    public static function section(array $rules): ?string
    {
        if ($rules === []) {
            return null;
        }

        return "## Project conventions (the code you write MUST follow these; they override your defaults)\n"
            .implode("\n", array_map(fn (string $rule) => "- {$rule}", $rules));
    }
}
