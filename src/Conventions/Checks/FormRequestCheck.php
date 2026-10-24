<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions\Checks;

use Abdelhmed\Chimera\Conventions\AstQuery;
use Abdelhmed\Chimera\Data\FileSpec;
use Abdelhmed\Chimera\Enums\FileType;
use PhpParser\Node;

final class FormRequestCheck implements ConventionCheck
{
    public function id(): string
    {
        return 'validation.form-request';
    }

    public function violations(FileSpec $spec, array $stmts, array $modelNames): array
    {
        if ($spec->type !== FileType::Controller) {
            return [];
        }

        $found = [];

        foreach (AstQuery::methods($stmts) as $method) {
            $inline = AstQuery::has($method->stmts ?? [], fn (Node $n) => ($n instanceof Node\Expr\MethodCall && $n->name instanceof Node\Identifier && in_array($n->name->toString(), ['validate', 'validateWithBag'], true))
                || ($n instanceof Node\Expr\StaticCall && AstQuery::lastName($n->class) === 'Validator'));

            if ($inline) {
                $found[] = basename($spec->path, '.php').'::'.$method->name->toString().'() validates inline; move the rules into a Form Request class and type-hint it.';
            }
        }

        return $found;
    }
}
