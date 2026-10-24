<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions\Detectors;

use Abdelhmed\Chimera\Conventions\AstQuery;
use Abdelhmed\Chimera\Conventions\Convention;
use Abdelhmed\Chimera\Conventions\Dominance;
use Abdelhmed\Chimera\Conventions\ParsedFile;
use Abdelhmed\Chimera\Conventions\ProjectCorpus;
use PhpParser\Node;

/** How Eloquent models are written: mass assignment, factories, soft deletes, casts. */
final class ModelDetector implements ConventionDetector
{
    public function detect(ProjectCorpus $corpus): array
    {
        $models = $corpus->files('app/Models');
        $total = count($models);

        if ($total < 3) {
            return [];
        }

        $found = [];

        $assignment = Dominance::of([
            'fillable' => $this->count($models, fn (ParsedFile $f) => $this->hasProperty($f, 'fillable')),
            'guarded' => $this->count($models, fn (ParsedFile $f) => $this->hasProperty($f, 'guarded')),
        ]);
        if ($assignment !== null) {
            $found[] = $assignment['key'] === 'fillable'
                ? new Convention('models.fillable', 'Declare $fillable on every model; never use $guarded = [].', $assignment['share'], "{$assignment['count']} of {$total} models")
                : new Convention('models.guarded', 'Protect models with $guarded instead of $fillable.', $assignment['share'], "{$assignment['count']} of {$total} models");
        }

        foreach (['HasFactory' => ['models.has-factory', 'Models use the HasFactory trait and have a matching factory in database/factories.'], 'SoftDeletes' => ['models.soft-deletes', 'Models use the SoftDeletes trait (and a deleted_at column) instead of hard deletes.']] as $trait => [$id, $rule]) {
            $with = $this->count($models, fn (ParsedFile $f) => AstQuery::has($f->stmts, fn (Node $n) => $n instanceof Node\Stmt\TraitUse && AstQuery::has($n->traits, fn (Node $t) => $t instanceof Node\Name && $t->getLast() === $trait)));

            if ($with / $total >= 0.7) {
                $found[] = new Convention($id, $rule, $with / $total, "{$with} of {$total} models");
            }
        }

        $casts = Dominance::of([
            'method' => $this->count($models, fn (ParsedFile $f) => AstQuery::has($f->stmts, fn (Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'casts')),
            'property' => $this->count($models, fn (ParsedFile $f) => $this->hasProperty($f, 'casts')),
        ], 2);
        if ($casts !== null) {
            $found[] = $casts['key'] === 'method'
                ? new Convention('models.casts-method', 'Declare attribute casts in a protected casts(): array method, not a $casts property.', $casts['share'], "{$casts['count']} of {$casts['total']} models with casts")
                : new Convention('models.casts-property', 'Declare attribute casts in a protected $casts property.', $casts['share'], "{$casts['count']} of {$casts['total']} models with casts");
        }

        return $found;
    }

    /** @param list<ParsedFile> $files */
    private function count(array $files, callable $predicate): int
    {
        return count(array_filter($files, $predicate));
    }

    private function hasProperty(ParsedFile $file, string $name): bool
    {
        return AstQuery::has($file->stmts, fn (Node $n) => $n instanceof Node\PropertyItem && $n->name->toString() === $name);
    }
}
