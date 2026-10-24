<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions;

use PhpParser\Node;
use PhpParser\NodeFinder;

/** Small AST questions shared by the detectors and the checks. */
final class AstQuery
{
    /** @param array<Node> $nodes */
    public static function has(array $nodes, callable $predicate): bool
    {
        return (new NodeFinder())->findFirst($nodes, $predicate) !== null;
    }

    /**
     * @param  array<Node>  $nodes
     * @return list<Node>
     */
    public static function find(array $nodes, callable $predicate): array
    {
        return (new NodeFinder())->find($nodes, $predicate);
    }

    /**
     * @param  array<Node>  $nodes
     * @return list<Node\Stmt\ClassMethod>
     */
    public static function methods(array $nodes): array
    {
        return (new NodeFinder())->findInstanceOf($nodes, Node\Stmt\ClassMethod::class);
    }

    public static function lastName(?Node $node): ?string
    {
        return match (true) {
            $node instanceof Node\Name => $node->getLast(),
            $node instanceof Node\Identifier => $node->toString(),
            $node instanceof Node\NullableType => self::lastName($node->type),
            default => null,
        };
    }

    /** True when the statements start with declare(strict_types=1). @param array<Node> $stmts */
    public static function hasStrictTypes(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\Declare_) {
                return false;
            }

            foreach ($stmt->declares as $declare) {
                if ($declare->key->toString() === 'strict_types') {
                    return true;
                }
            }
        }

        return false;
    }
}
