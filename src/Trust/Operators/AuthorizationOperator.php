<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Trust\Operators;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Nop;

/** `$this->authorize('update', $task);` and `abort_if(...)` guards are deleted: tests that only use the owner will not notice. */
final class AuthorizationOperator implements MutationOperator
{
    private const GUARDS = ['authorize', 'abort_if', 'abort_unless'];

    public function name(): string
    {
        return 'guard';
    }

    public function applies(Node $node): bool
    {
        return $node instanceof Expression && $this->guardName($node->expr) !== null;
    }

    public function describe(Node $node): string
    {
        /** @var Expression $node */
        return 'removed the guard `'.$this->guardName($node->expr).'()`';
    }

    public function mutate(Node $node): Node
    {
        return new Nop();
    }

    private function guardName(Node $expr): ?string
    {
        $name = match (true) {
            $expr instanceof MethodCall, $expr instanceof StaticCall => $expr->name instanceof Node\Identifier ? $expr->name->toString() : null,
            $expr instanceof FuncCall => $expr->name instanceof Node\Name ? $expr->name->getLast() : null,
            default => null,
        };

        return $name !== null && in_array($name, self::GUARDS, true) ? $name : null;
    }
}
