<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Support;

use Abdelhmed\Chimera\Exceptions\InvalidAiResponseException;
use JsonException;

final class JsonDecoder
{
    /**
     * Decode a model reply, tolerating a BOM and markdown code fences.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidAiResponseException
     */
    public static function decode(string $raw): array
    {
        $json = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $raw));

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $json, $m) === 1) {
            $json = $m[1];
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidAiResponseException('The AI reply was not valid JSON: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($data)) {
            throw new InvalidAiResponseException('The AI reply was valid JSON but not an object.');
        }

        return $data;
    }
}
