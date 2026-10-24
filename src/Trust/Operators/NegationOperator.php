<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Trust\Operators;

use PhpParser\Node;
use PhpParser\Node\Expr\BooleanNot;

/** `if (! $user->isOwner())` becomes `if ($user->isOwner())`. */
final class NegationOperator implements MutationOperator
{
    public function name(): string
    {
        return 'negation';
    }

    public function applies(Node $node): bool
    {
        return $node instanceof BooleanNot;
    }

    public function describe(Node $node): string
    {
        return 'removed a `!` negation';
    }

    public function mutate(Node $node): Node
    {
        /** @var BooleanNot $node */
        return $node->expr;
    }
}
