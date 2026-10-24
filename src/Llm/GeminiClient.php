<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Llm;

use Abdelhmed\Chimera\Contracts\LlmClient;
use Abdelhmed\Chimera\Exceptions\ConfigurationException;
use Abdelhmed\Chimera\Exceptions\LlmRequestException;
use Abdelhmed\Chimera\Exceptions\ResponseTruncatedException;
use Abdelhmed\Chimera\Support\JsonDecoder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

/** Google Gemini over REST, forced into JSON mode with a response schema. */
final class GeminiClient implements LlmClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly GeminiSettings $settings,
    ) {
    }

    public function generateJson(string $systemPrompt, string $userPrompt, array $responseSchema): array
    {
        if ($this->settings->apiKey === '') {
            throw new ConfigurationException('GEMINI_API_KEY is not set. Add it to your .env file.');
        }

        $url = rtrim($this->settings->baseUrl, '/')."/models/{$this->settings->model}:generateContent";

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
            'generationConfig' => [
                'temperature' => $this->settings->temperature,
                'maxOutputTokens' => $this->settings->maxOutputTokens,
                'responseMimeType' => 'application/json',
                'responseSchema' => $responseSchema,
            ],
        ];

        try {
            $response = $this->http
                ->withHeaders(['x-goog-api-key' => $this->settings->apiKey])
                ->acceptJson()
                ->timeout($this->settings->timeout)
                ->retry($this->settings->retries, 1500, fn (Throwable $e) => $this->isRetryable($e))
                ->post($url, $payload);
        } catch (RequestException $e) {
            throw new LlmRequestException(
                'Gemini API error ('.$e->response->status().'): '.($e->response->json('error.message') ?? $e->response->body()),
                0,
                $e,
            );
        } catch (ConnectionException $e) {
            throw new LlmRequestException('Could not reach the Gemini API: '.$e->getMessage(), 0, $e);
        }

        return JsonDecoder::decode($this->extractText($response));
    }

    private function extractText(Response $response): string
    {
        $candidate = $response->json('candidates.0');

        if (! is_array($candidate)) {
            $blocked = $response->json('promptFeedback.blockReason');

            throw new LlmRequestException($blocked ? "Gemini blocked the prompt ({$blocked})." : 'Gemini returned no answer.');
        }

        $finish = (string) ($candidate['finishReason'] ?? 'STOP');

        if ($finish === 'MAX_TOKENS') {
            throw new ResponseTruncatedException('Gemini stopped because it reached the output token limit.');
        }

        if (in_array($finish, ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'], true)) {
            throw new LlmRequestException("Gemini declined to answer ({$finish}).");
        }

        $text = '';
        foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
            if (isset($part['text']) && ! ($part['thought'] ?? false)) {
                $text .= $part['text'];
            }
        }

        return $text;
    }

    private function isRetryable(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException && in_array($e->response->status(), [408, 429, 500, 502, 503, 504], true);
    }
}
