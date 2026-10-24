<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Trust;

use Abdelhmed\Chimera\Trust\Operators\MutationOperator;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/** Pass 1: lists every place where some operator could introduce a bug. */
final class CandidateCollector extends NodeVisitorAbstract
{
    /** @var list<array{operator: int, ordinal: int, line: int, description: string}> */
    public array $found = [];

    /** @var array<int, int> */
    private array $ordinals = [];

    /** @param list<MutationOperator> $operators */
    public function __construct(private readonly array $operators)
    {
    }

    public function enterNode(Node $node): null
    {
        foreach ($this->operators as $index => $operator) {
            if ($operator->applies($node)) {
                $this->found[] = [
                    'operator' => $index,
                    'ordinal' => $this->ordinals[$index] = ($this->ordinals[$index] ?? -1) + 1,
                    'line' => $node->getStartLine(),
                    'description' => $operator->describe($node),
                ];
            }
        }

        return null;
    }
}
