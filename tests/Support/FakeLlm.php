<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Tests\Support;

use Abdelhmed\Chimera\Contracts\LlmClient;
use RuntimeException;
use Throwable;

/** Plays back scripted replies (arrays, exceptions to throw, or closures) in order and records every prompt. */
final class FakeLlm implements LlmClient
{
    /** @var list<string> */
    public array $userPrompts = [];

    /** @param list<array<string, mixed>|Throwable|callable> $script */
    public function __construct(private array $script)
    {
    }

    public function generateJson(string $systemPrompt, string $userPrompt, array $responseSchema): array
    {
        $this->userPrompts[] = $userPrompt;
        $next = array_shift($this->script) ?? throw new RuntimeException('FakeLlm ran out of scripted replies.');

        if ($next instanceof Throwable) {
            throw $next;
        }

        return is_callable($next) ? $next($userPrompt) : $next;
    }
}
