<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions\Checks;

use Abdelhmed\Chimera\Conventions\AstQuery;
use Abdelhmed\Chimera\Data\FileSpec;

final class ReturnTypesCheck implements ConventionCheck
{
    public function id(): string
    {
        return 'php.return-types';
    }

    public function violations(FileSpec $spec, array $stmts, array $modelNames): array
    {
        if (! str_starts_with($spec->path, 'app/')) {
            return [];
        }

        $found = [];

        foreach (AstQuery::methods($stmts) as $method) {
            if ($method->returnType === null && ! str_starts_with($method->name->toString(), '__')) {
                $found[] = basename($spec->path, '.php').'::'.$method->name->toString().'() has no return type.';
            }
        }

        return $found;
    }
}
