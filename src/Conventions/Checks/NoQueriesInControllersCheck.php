<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions\Checks;

use Abdelhmed\Chimera\Conventions\AstQuery;
use Abdelhmed\Chimera\Data\FileSpec;
use Abdelhmed\Chimera\Enums\FileType;
use PhpParser\Node;

final class NoQueriesInControllersCheck implements ConventionCheck
{
    public function id(): string
    {
        return 'architecture.no-queries-in-controllers';
    }

    public function violations(FileSpec $spec, array $stmts, array $modelNames): array
    {
        if ($spec->type !== FileType::Controller) {
            return [];
        }

        $targets = [...$modelNames, 'DB'];
        $found = [];

        foreach (AstQuery::find($stmts, fn (Node $n) => $n instanceof Node\Expr\StaticCall && $n->class instanceof Node\Name && in_array($n->class->getLast(), $targets, true)) as $call) {
            $name = $call->name instanceof Node\Identifier ? $call->name->toString() : '?';
            $found[] = basename($spec->path, '.php').' calls '.$call->class->getLast().'::'.$name.'() directly; move the query into a service, action or model scope.';
        }

        return $found;
    }
}
