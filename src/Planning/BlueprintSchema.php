<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Planning;

final class BlueprintSchema
{
    /** @return array<string, mixed> */
    public static function definition(): array
    {
        $text = ['type' => 'STRING'];
        $list = ['type' => 'ARRAY', 'items' => $text];

        return [
            'type' => 'OBJECT',
            'properties' => [
                'summary' => $text,
                'impact' => [
                    'type' => 'OBJECT',
                    'properties' => ['hasBreakingChanges' => ['type' => 'BOOLEAN'], 'warnings' => $list],
                ],
                'composerPackages' => $list,
                'envVariables' => $list,
                'files' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => ['path' => $text, 'type' => $text, 'action' => $text, 'description' => $text],
                        'required' => ['path', 'type', 'action', 'description'],
                    ],
                ],
            ],
            'required' => ['summary', 'files'],
        ];
    }
}
