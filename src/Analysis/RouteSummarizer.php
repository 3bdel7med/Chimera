<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Analysis;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Throwable;

/** Lists the Route::verb(...) calls of a routes file as "GET /posts -> PostController@index" (group prefixes are not resolved). */
final class RouteSummarizer
{
    private const VERBS = ['get', 'post', 'put', 'patch', 'delete', 'any', 'options', 'resource', 'apiResource', 'view'];

    /** @return list<string> */
    public function summarize(string $code, int $limit): array
    {
        try {
            $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        } catch (Throwable) {
            return [];
        }

        $lines = [];

        foreach ((new NodeFinder())->findInstanceOf($stmts ?? [], Node\Expr\StaticCall::class) as $call) {
            if (
                ! $call->class instanceof Node\Name
                || $call->class->getLast() !== 'Route'
                || ! $call->name instanceof Node\Identifier
                || ! in_array($call->name->toString(), self::VERBS, true)
            ) {
                continue;
            }

            $uri = $this->stringArg($call->args[0] ?? null) ?? '?';
            $target = $this->target($call->args[1] ?? null);
            $lines[] = strtoupper($call->name->toString()).' /'.ltrim($uri, '/').($target !== null ? " -> {$target}" : '');
        }

        return array_slice($lines, 0, $limit);
    }

    private function stringArg(mixed $arg): ?string
    {
        return $arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_ ? $arg->value->value : null;
    }

    private function target(mixed $arg): ?string
    {
        if (! $arg instanceof Node\Arg) {
            return null;
        }

        $value = $arg->value;

        if ($value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name) {
            return $value->class->getLast();
        }

        if ($value instanceof Node\Expr\Array_ && count($value->items) === 2) {
            [$class, $method] = $value->items;

            if (
                $class?->value instanceof Node\Expr\ClassConstFetch
                && $class->value->class instanceof Node\Name
                && $method?->value instanceof Node\Scalar\String_
            ) {
                return $class->value->class->getLast().'@'.$method->value->value;
            }
        }

        return null;
    }
}
