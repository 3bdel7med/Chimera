<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions\Detectors;

use Abdelhmed\Chimera\Conventions\AstQuery;
use Abdelhmed\Chimera\Conventions\Convention;
use Abdelhmed\Chimera\Conventions\ProjectCorpus;
use PhpParser\Node;

/** Where does business logic live, and do controllers talk to the database directly? */
final class ArchitectureDetector implements ConventionDetector
{
    public function detect(ProjectCorpus $corpus): array
    {
        $found = [];

        if (($services = count($corpus->files('app/Services'))) >= 2) {
            $found[] = new Convention('architecture.services', 'Put business logic in service classes under app/Services and inject them into controllers; keep controllers thin.', 1.0, "{$services} classes in app/Services");
        }

        if (($actions = count($corpus->files('app/Actions'))) >= 2) {
            $found[] = new Convention('architecture.actions', 'Put business logic in single-purpose Action classes under app/Actions; keep controllers thin.', 1.0, "{$actions} classes in app/Actions");
        }

        $models = array_map(fn ($f) => basename($f->path, '.php'), $corpus->files('app/Models'));
        $controllers = $corpus->files('app/Http/Controllers');
        $querying = 0;

        foreach ($controllers as $file) {
            if (AstQuery::has($file->stmts, fn (Node $n) => $n instanceof Node\Expr\StaticCall && in_array(AstQuery::lastName($n->class instanceof Node\Name ? $n->class : null), [...$models, 'DB'], true))) {
                $querying++;
            }
        }

        $total = count($controllers);
        if ($total >= 3 && $models !== [] && $querying / $total <= 0.2) {
            $found[] = new Convention('architecture.no-queries-in-controllers', 'Do not query the database directly in controllers (no Model::where() or DB::); delegate to services, actions or model scopes.', 1 - $querying / $total, "only {$querying} of {$total} controllers query the database directly");
        }

        return $found;
    }
}
