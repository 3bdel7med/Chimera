<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions\Detectors;

use Abdelhmed\Chimera\Conventions\AstQuery;
use Abdelhmed\Chimera\Conventions\Convention;
use Abdelhmed\Chimera\Conventions\ProjectCorpus;
use PhpParser\Node;

final class RoutingDetector implements ConventionDetector
{
    public function detect(ProjectCorpus $corpus): array
    {
        $resourceRoutes = 0;
        $sanctum = false;

        foreach ($corpus->files('routes') as $file) {
            $resourceRoutes += count(AstQuery::find($file->stmts, fn (Node $n) => $n instanceof Node\Expr\StaticCall
                && AstQuery::lastName($n->class instanceof Node\Name ? $n->class : null) === 'Route'
                && $n->name instanceof Node\Identifier
                && in_array($n->name->toString(), ['apiResource', 'resource'], true)));

            $sanctum = $sanctum || AstQuery::has($file->stmts, fn (Node $n) => $n instanceof Node\Scalar\String_ && str_starts_with($n->value, 'auth:sanctum'));
        }

        $found = [];

        if ($resourceRoutes >= 2) {
            $found[] = new Convention('routing.resource-routes', 'Register CRUD endpoints with Route::apiResource() (or Route::resource()) instead of one route per action.', 1.0, "{$resourceRoutes} resource routes");
        }

        if ($sanctum) {
            $found[] = new Convention('routing.auth-sanctum', 'Protect API routes with the auth:sanctum middleware.', 1.0, 'auth:sanctum found in routes/');
        }

        return $found;
    }
}
