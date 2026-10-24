<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Enums;

enum FileState: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Generated = 'generated';
    case Written = 'written';
    case Unchanged = 'unchanged';
    case Healed = 'healed';

    public function symbol(): string
    {
        return match ($this) {
            self::Pending => '○',
            self::Generating => '◐',
            self::Generated => '●',
            self::Written, self::Healed => '✔',
            self::Unchanged => '–',
        };
    }

    public function verb(): string
    {
        return match ($this) {
            self::Pending => 'planned',
            self::Generating => 'generating',
            self::Generated => 'generated',
            self::Written => 'written',
            self::Unchanged => 'unchanged',
            self::Healed => 'fixed',
        };
    }
}
