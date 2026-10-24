<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions\Checks;

use Abdelhmed\Chimera\Conventions\AstQuery;
use Abdelhmed\Chimera\Data\FileSpec;
use Abdelhmed\Chimera\Enums\FileType;
use PhpParser\Node;

final class PhpUnitStyleCheck implements ConventionCheck
{
    public function id(): string
    {
        return 'tests.phpunit';
    }

    public function violations(FileSpec $spec, array $stmts, array $modelNames): array
    {
        if ($spec->type !== FileType::Test) {
            return [];
        }

        $pest = AstQuery::has($stmts, fn (Node $n) => $n instanceof Node\Expr\FuncCall && in_array(AstQuery::lastName($n->name instanceof Node\Name ? $n->name : null), ['it', 'test'], true));

        return $pest ? ['This project writes PHPUnit test classes extending Tests\TestCase; rewrite these Pest tests as a class.'] : [];
    }
}
