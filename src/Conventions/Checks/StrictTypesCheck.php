<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions\Checks;

use Abdelhmed\Chimera\Conventions\AstQuery;
use Abdelhmed\Chimera\Data\FileSpec;

final class StrictTypesCheck implements ConventionCheck
{
    public function id(): string
    {
        return 'php.strict-types';
    }

    public function violations(FileSpec $spec, array $stmts, array $modelNames): array
    {
        if (! str_starts_with($spec->path, 'app/') || AstQuery::hasStrictTypes($stmts)) {
            return [];
        }

        return ['The file does not start with declare(strict_types=1);'];
    }
}
