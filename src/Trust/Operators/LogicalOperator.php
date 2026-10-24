<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Trust\Operators;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp;

/** `&&` becomes `||` (and back): conditions that need several things to be true are rarely tested one by one. */
final class LogicalOperator implements MutationOperator
{
    private const MAP = [
        BinaryOp\BooleanAnd::class => [BinaryOp\BooleanOr::class, '&&', '||'],
        BinaryOp\BooleanOr::class => [BinaryOp\BooleanAnd::class, '||', '&&'],
        BinaryOp\LogicalAnd::class => [BinaryOp\LogicalOr::class, 'and', 'or'],
        BinaryOp\LogicalOr::class => [BinaryOp\LogicalAnd::class, 'or', 'and'],
    ];

    public function name(): string
    {
        return 'logical';
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
