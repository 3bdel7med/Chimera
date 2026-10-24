<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Trust\Operators;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\Int_;

/** `response()->json($x, 201)` becomes 200, `abort(403)` becomes 404: tests that only check the body will not notice. */
final class StatusCodeOperator implements MutationOperator
{
    /** @var array<int, int> */
    private const NEXT = [200 => 201, 201 => 200, 204 => 200, 400 => 422, 401 => 403, 403 => 404, 404 => 403, 409 => 400, 422 => 400];

    /** Which argument carries the status code for each call. */
    private const ARG_INDEX = ['json' => 1, 'response' => 1, 'abort' => 0, 'abort_if' => 2, 'abort_unless' => 2, 'setstatuscode' => 0, 'status' => 0];

    public function name(): string
    {
        return 'status-code';
    }

    public function applies(Node $node): bool
    {
        return $this->statusArg($node) !== null;
    }

    public function describe(Node $node): string
    {
        $arg = $this->statusArg($node);
        $code = $arg->value->value;

        return "changed status code {$code} to ".self::NEXT[$code].' in '.$this->callName($node).'()';
    }

    public function mutate(Node $node): Node
    {
        $arg = $this->statusArg($node);
        $arg->value = new Int_(self::NEXT[$arg->value->value]);

        return $node;
    }

    private function statusArg(Node $node): ?Node\Arg
    {
        $name = $this->callName($node);

        if ($name === null || ! isset(self::ARG_INDEX[strtolower($name)])) {
            return null;
        }

        $arg = $node->args[self::ARG_INDEX[strtolower($name)]] ?? null;

        return $arg instanceof Node\Arg && $arg->value instanceof Int_ && isset(self::NEXT[$arg->value->value]) ? $arg : null;
    }

    private function callName(Node $node): ?string
    {
        return match (true) {
            $node instanceof FuncCall && $node->name instanceof Node\Name => $node->name->getLast(),
            ($node instanceof MethodCall || $node instanceof StaticCall) && $node->name instanceof Node\Identifier => $node->name->toString(),
            default => null,
        };
    }
}
