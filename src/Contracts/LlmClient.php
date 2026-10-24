<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Contracts;

use Abdelhmed\Chimera\Exceptions\InvalidAiResponseException;
use Abdelhmed\Chimera\Exceptions\LlmRequestException;
use Abdelhmed\Chimera\Exceptions\ResponseTruncatedException;

interface LlmClient
{
    /**
     * Send a prompt and get back a decoded JSON object that follows $responseSchema.
     *
     * @param  array<string, mixed>  $responseSchema
     * @return array<string, mixed>
     *
     * @throws ResponseTruncatedException when the model ran out of output tokens
     * @throws InvalidAiResponseException when the reply is not usable JSON
     * @throws LlmRequestException when the request itself fails
     */
    public function generateJson(string $systemPrompt, string $userPrompt, array $responseSchema): array;
}
