<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Llm;

final class GeminiSettings
{
    public function __construct(
        public readonly string $apiKey,
        public readonly string $model = 'gemini-2.5-flash',
        public readonly string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        public readonly int $timeout = 180,
        public readonly int $maxOutputTokens = 32768,
        public readonly float $temperature = 0.2,
        public readonly int $retries = 3,
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        return new self(
            apiKey: (string) ($config['api_key'] ?? ''),
            model: (string) ($config['model'] ?? 'gemini-2.5-flash'),
            baseUrl: (string) ($config['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'),
            timeout: (int) ($config['timeout'] ?? 180),
            maxOutputTokens: (int) ($config['max_output_tokens'] ?? 32768),
            temperature: (float) ($config['temperature'] ?? 0.2),
            retries: (int) ($config['retries'] ?? 3),
        );
    }
}
