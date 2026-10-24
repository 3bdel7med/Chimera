<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions\Detectors;

use Abdelhmed\Chimera\Conventions\AstQuery;
use Abdelhmed\Chimera\Conventions\Convention;
use Abdelhmed\Chimera\Conventions\Dominance;
use Abdelhmed\Chimera\Conventions\ParsedFile;
use Abdelhmed\Chimera\Conventions\ProjectCorpus;
use PhpParser\Node;

final class MigrationDetector implements ConventionDetector
{
    public function detect(ProjectCorpus $corpus): array
    {
        $migrations = $corpus->files('database/migrations');
        $total = count($migrations);

        if ($total < 3) {
            return [];
        }

        $found = [];

        $anonymous = count(array_filter($migrations, fn (ParsedFile $f) => AstQuery::has($f->stmts, fn (Node $n) => $n instanceof Node\Expr\New_ && $n->class instanceof Node\Stmt\Class_)));
        if ($anonymous / $total >= 0.7) {
            $found[] = new Convention('migrations.anonymous', 'Write migrations as anonymous classes (return new class extends Migration).', $anonymous / $total, "{$anonymous} of {$total} migrations");
        }

        $foreign = Dominance::of([
            'constrained' => $this->calls($migrations, 'constrained'),
            'manual' => $this->calls($migrations, 'foreign'),
        ], 2);
        if ($foreign !== null && $foreign['key'] === 'constrained') {
            $found[] = new Convention('migrations.constrained', 'Declare foreign keys with foreignId(...)->constrained().', $foreign['share'], "{$foreign['count']} of {$foreign['total']} foreign keys");
        }

        $withDown = count(array_filter($migrations, fn (ParsedFile $f) => AstQuery::has($f->stmts, fn (Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'down')));
        if ($withDown / $total >= 0.9) {
            $found[] = new Convention('migrations.down', 'Every migration implements a working down() method.', $withDown / $total, "{$withDown} of {$total} migrations");
        }

        return $found;
    }

    /** @param list<ParsedFile> $files */
    private function calls(array $files, string $method): int
    {
        $count = 0;
        foreach ($files as $file) {
            $count += count(AstQuery::find($file->stmts, fn (Node $n) => $n instanceof Node\Expr\MethodCall && $n->name instanceof Node\Identifier && $n->name->toString() === $method));
        }

        return $count;
    }
}
