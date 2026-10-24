<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions\Detectors;

use Abdelhmed\Chimera\Conventions\AstQuery;
use Abdelhmed\Chimera\Conventions\Convention;
use Abdelhmed\Chimera\Conventions\Dominance;
use Abdelhmed\Chimera\Conventions\ParsedFile;
use Abdelhmed\Chimera\Conventions\ProjectCorpus;
use PhpParser\Node;

/** Pest or PHPUnit, and how the database is reset. */
final class TestingDetector implements ConventionDetector
{
    public function detect(ProjectCorpus $corpus): array
    {
        $tests = array_values(array_filter(
            $corpus->files('tests'),
            fn (ParsedFile $f) => ! in_array(basename($f->path), ['Pest.php', 'TestCase.php', 'CreatesApplication.php'], true),
        ));

        $found = [];

        $style = Dominance::of([
            'pest' => count(array_filter($tests, fn (ParsedFile $f) => $this->isPest($f))),
            'phpunit' => count(array_filter($tests, fn (ParsedFile $f) => $this->isPhpUnit($f))),
        ], 2);

        if ($style !== null) {
            $found[] = $style['key'] === 'pest'
                ? new Convention('tests.pest', 'Write tests with Pest: it()/test() closures and expect() assertions, never PHPUnit test classes.', $style['share'], "{$style['count']} of {$style['total']} test files")
                : new Convention('tests.phpunit', 'Write PHPUnit test classes that extend Tests\TestCase; do not use Pest functions.', $style['share'], "{$style['count']} of {$style['total']} test files");
        }

        $pestFile = array_values(array_filter($corpus->files('tests'), fn (ParsedFile $f) => basename($f->path) === 'Pest.php'));
        if ($pestFile !== [] && $this->mentionsRefreshDatabase($pestFile[0])) {
            $found[] = new Convention('tests.refresh-database-global', 'RefreshDatabase is already applied globally in tests/Pest.php; do not add it to individual tests.', 1.0, 'configured in tests/Pest.php');
        } else {
            $feature = array_values(array_filter($tests, fn (ParsedFile $f) => str_starts_with($f->path, 'tests/Feature/')));
            $with = count(array_filter($feature, fn (ParsedFile $f) => $this->mentionsRefreshDatabase($f)));

            if (count($feature) >= 2 && $with / count($feature) >= 0.7) {
                $found[] = new Convention('tests.refresh-database', 'Feature tests that touch the database use the RefreshDatabase trait.', $with / count($feature), "{$with} of ".count($feature).' feature tests');
            }
        }

        return $found;
    }

    private function isPest(ParsedFile $file): bool
    {
        foreach ($file->stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\Expression) {
                continue;
            }

            $expr = $stmt->expr;
            while ($expr instanceof Node\Expr\MethodCall) {
                $expr = $expr->var;
            }

            if ($expr instanceof Node\Expr\FuncCall && in_array(AstQuery::lastName($expr->name instanceof Node\Name ? $expr->name : null), ['it', 'test', 'describe'], true)) {
                return true;
            }
        }

        return false;
    }

    private function isPhpUnit(ParsedFile $file): bool
    {
        return AstQuery::has($file->stmts, fn (Node $n) => $n instanceof Node\Stmt\Class_ && str_ends_with((string) AstQuery::lastName($n->extends), 'TestCase'));
    }

    private function mentionsRefreshDatabase(ParsedFile $file): bool
    {
        return AstQuery::has($file->stmts, fn (Node $n) => $n instanceof Node\Name && $n->getLast() === 'RefreshDatabase');
    }
}
