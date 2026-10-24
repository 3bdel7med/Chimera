<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Trust;

use Abdelhmed\Chimera\Trust\Operators\MutationOperator;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/** Pass 2: applies exactly one mutation, the n-th match of one operator, and leaves everything else alone. */
final class NthMutator extends NodeVisitorAbstract
{
    private int $seen = 0;

    public function __construct(
        private readonly MutationOperator $operator,
        private readonly int $ordinal,
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        if (! $this->operator->applies($node)) {
            return null;
        }

        return $this->seen++ === $this->ordinal ? $this->operator->mutate($node) : null;
    }
}
