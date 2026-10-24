<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions;

use PhpParser\Node\Stmt;

final class ParsedFile
{
    /** @param list<Stmt> $stmts */
    public function __construct(
        public readonly string $path,
        public readonly array $stmts,
    ) {
    }
}
