<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Trust\Operators;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp;

/** `==` becomes `!=`, `>` becomes `<=` and so on: a test that never hits the boundary will not notice. */
final class ComparisonOperator implements MutationOperator
{
    /** @var array<class-string<BinaryOp>, array{0: class-string<BinaryOp>, 1: string, 2: string}> */
    private const MAP = [
        BinaryOp\Equal::class => [BinaryOp\NotEqual::class, '==', '!='],
        BinaryOp\NotEqual::class => [BinaryOp\Equal::class, '!=', '=='],
        BinaryOp\Identical::class => [BinaryOp\NotIdentical::class, '===', '!=='],
        BinaryOp\NotIdentical::class => [BinaryOp\Identical::class, '!==', '==='],
        BinaryOp\Greater::class => [BinaryOp\SmallerOrEqual::class, '>', '<='],
        BinaryOp\GreaterOrEqual::class => [BinaryOp\Smaller::class, '>=', '<'],
        BinaryOp\Smaller::class => [BinaryOp\GreaterOrEqual::class, '<', '>='],
        BinaryOp\SmallerOrEqual::class => [BinaryOp\Greater::class, '<=', '>'],
    ];

    public function name(): string
    {
        return 'comparison';
    }

    public function applies(Node $node): bool
    {
        return isset(self::MAP[$node::class]);
    }

    public function describe(Node $node): string
    {
        [, $from, $to] = self::MAP[$node::class];

        return "changed `{$from}` to `{$to}`";
    }

    public function mutate(Node $node): Node
    {
        /** @var BinaryOp $node */
        $class = self::MAP[$node::class][0];

        return new $class($node->left, $node->right);
    }
}
