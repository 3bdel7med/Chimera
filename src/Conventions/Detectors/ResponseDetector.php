<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions\Detectors;

use Abdelhmed\Chimera\Conventions\AstQuery;
use Abdelhmed\Chimera\Conventions\Convention;
use Abdelhmed\Chimera\Conventions\Dominance;
use Abdelhmed\Chimera\Conventions\ProjectCorpus;
use PhpParser\Node;

/** API Resources or raw `response()->json()` in controllers? */
final class ResponseDetector implements ConventionDetector
{
    public function detect(ProjectCorpus $corpus): array
    {
        $counts = ['resources' => 0, 'json' => 0];

        foreach ($corpus->files('app/Http/Controllers') as $file) {
            foreach (AstQuery::methods($file->stmts) as $method) {
                $body = $method->stmts ?? [];

                if (AstQuery::has($body, fn (Node $n) => $this->isResource($n))) {
                    $counts['resources']++;
                } elseif (AstQuery::has($body, fn (Node $n) => $n instanceof Node\Expr\MethodCall && $n->name instanceof Node\Identifier && $n->name->toString() === 'json')) {
                    $counts['json']++;
                }
            }
        }

        $winner = Dominance::of($counts);

        return match ($winner['key'] ?? null) {
            'resources' => [new Convention('responses.resources', 'Return API Resource classes (app/Http/Resources) from controllers instead of raw arrays or models.', $winner['share'], "{$winner['count']} of {$winner['total']} controller actions")],
            'json' => [new Convention('responses.json', 'Return JSON with response()->json(); do not introduce API Resource classes.', $winner['share'], "{$winner['count']} of {$winner['total']} controller actions")],
            default => [],
        };
    }

    private function isResource(Node $node): bool
    {
        return ($node instanceof Node\Expr\New_ && str_ends_with((string) AstQuery::lastName($node->class instanceof Node\Name ? $node->class : null), 'Resource'))
            || ($node instanceof Node\Expr\StaticCall && str_ends_with((string) AstQuery::lastName($node->class instanceof Node\Name ? $node->class : null), 'Resource'));
    }
}
