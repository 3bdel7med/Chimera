<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Trust\Operators;

use PhpParser\Node;

/** One kind of deliberate bug. Operators are stateless and work on single AST nodes. */
interface MutationOperator
{
    public function name(): string;

    public function applies(Node $node): bool;

    /** Text for the report; called on the untouched node. */
    public function describe(Node $node): string;

    /** Return the node that replaces $node. $node is a private clone and may be edited in place. */
    public function mutate(Node $node): Node;
}
