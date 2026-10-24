<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Generation;

final class FileBatchSchema
{
    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'files' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => ['path' => ['type' => 'STRING'], 'content' => ['type' => 'STRING']],
                        'required' => ['path', 'content'],
                    ],
                ],
            ],
            'required' => ['files'],
        ];
    }
}
