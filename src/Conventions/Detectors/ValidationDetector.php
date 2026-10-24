<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions\Detectors;

use Abdelhmed\Chimera\Conventions\AstQuery;
use Abdelhmed\Chimera\Conventions\Convention;
use Abdelhmed\Chimera\Conventions\Dominance;
use Abdelhmed\Chimera\Conventions\ProjectCorpus;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;

/** Form Requests or inline `$request->validate()` in store/update actions? */
final class ValidationDetector implements ConventionDetector
{
    public function detect(ProjectCorpus $corpus): array
    {
        $counts = ['form-request' => 0, 'inline' => 0];

        foreach ($corpus->files('app/Http/Controllers') as $file) {
            foreach (AstQuery::methods($file->stmts) as $method) {
                if (! $method->isPublic() || ! in_array($method->name->toString(), ['store', 'update'], true)) {
                    continue;
                }

                if ($this->usesFormRequest($method)) {
                    $counts['form-request']++;
                } elseif ($this->validatesInline($method)) {
                    $counts['inline']++;
                }
            }
        }

        $winner = Dominance::of($counts);

        return match ($winner['key'] ?? null) {
            'form-request' => [new Convention('validation.form-request', 'Validate input with dedicated Form Request classes in app/Http/Requests; never validate inline in controllers.', $winner['share'], "{$winner['count']} of {$winner['total']} store/update actions")],
            'inline' => [new Convention('validation.inline', 'Validate input inline in the controller with $request->validate().', $winner['share'], "{$winner['count']} of {$winner['total']} store/update actions")],
            default => [],
        };
    }

    private function usesFormRequest(ClassMethod $method): bool
    {
        foreach ($method->params as $param) {
            $type = AstQuery::lastName($param->type);

            if ($type !== null && $type !== 'Request' && str_ends_with($type, 'Request')) {
                return true;
            }
        }

        return false;
    }

    private function validatesInline(ClassMethod $method): bool
    {
        return AstQuery::has($method->stmts ?? [], fn (Node $n) => ($n instanceof Node\Expr\MethodCall && $n->name instanceof Node\Identifier && in_array($n->name->toString(), ['validate', 'validateWithBag'], true))
            || ($n instanceof Node\Expr\StaticCall && AstQuery::lastName($n->class) === 'Validator'));
    }
}
