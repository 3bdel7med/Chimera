<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Data;

final class RollbackReport
{
    /** @var list<string> */
    public array $restored = [];

    /** @var list<string> */
    public array $deleted = [];

    public ?string $migrationOutput = null;

    /** @var list<string> */
    public array $warnings = [];
}
