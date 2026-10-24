<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Enums;

enum FileAction: string
{
    /** Write a brand-new file. */
    case Create = 'CREATE';

    /** Replace an existing file with a complete new version. */
    case Modify = 'MODIFY';

    /** Merge new members/statements into an existing PHP file using the AST. */
    case Inject = 'INJECT';

    public static function fromLoose(mixed $value): self
    {
        $key = strtoupper(trim((string) (is_scalar($value) ? $value : '')));

        return self::tryFrom($key) ?? match ($key) {
            'UPDATE', 'EDIT', 'PATCH', 'REPLACE' => self::Modify,
            'ADD', 'APPEND', 'MERGE' => self::Inject,
            default => self::Create,
        };
    }
}
