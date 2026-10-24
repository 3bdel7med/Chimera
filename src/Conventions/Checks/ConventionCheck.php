<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions\Checks;

use Abdelhmed\Chimera\Data\FileSpec;
use PhpParser\Node\Stmt;

/** A convention that can be verified on generated code without asking the AI. */
interface ConventionCheck
{
    /** The id of the convention this check enforces. */
    public function id(): string;

    /**
     * @param  list<Stmt>  $stmts  the parsed generated file
     * @param  list<string>  $modelNames  class names of the project's (and the generated) models
     * @return list<string> one message per violation, empty when the file complies
     */
    public function violations(FileSpec $spec, array $stmts, array $modelNames): array;
}
