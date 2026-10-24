<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Data;

final class ExecutionReport
{
    /** @var list<string> */
    public array $created = [];

    /** @var list<string> */
    public array $modified = [];

    /** @var list<string> */
    public array $injected = [];

    /** @var array<string, string> path => reason */
    public array $skipped = [];

    public function total(): int
    {
        return count($this->created) + count($this->modified) + count($this->injected);
    }
}
