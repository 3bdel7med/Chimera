<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions\Checks;

use Abdelhmed\Chimera\Conventions\AstQuery;
use Abdelhmed\Chimera\Data\FileSpec;
use Abdelhmed\Chimera\Enums\FileType;
use PhpParser\Node;

final class PestStyleCheck implements ConventionCheck
{
    public function id(): string
    {
        return 'tests.pest';
    }

    public function violations(FileSpec $spec, array $stmts, array $modelNames): array
    {
        if ($spec->type !== FileType::Test) {
            return [];
        }

        $phpunitClass = AstQuery::has($stmts, fn (Node $n) => $n instanceof Node\Stmt\Class_ && str_ends_with((string) AstQuery::lastName($n->extends), 'TestCase'));

        return $phpunitClass ? ['This project writes tests with Pest (it()/test() and expect()); rewrite this PHPUnit class as Pest tests.'] : [];
    }
}
