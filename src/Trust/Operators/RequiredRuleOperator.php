<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Trust\Operators;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;

/** `'title' => 'required|string'` becomes `'string'`: tests that never send an empty field will not notice. */
final class RequiredRuleOperator implements MutationOperator
{
    public function name(): string
    {
        return 'required-rule';
    }

    public function applies(Node $node): bool
    {
        if (! $node instanceof ArrayItem || ! $node->key instanceof String_) {
            return false;
        }

        if ($node->value instanceof String_) {
            return in_array('required', explode('|', $node->value->value), true);
        }

        return $node->value instanceof Array_ && $this->requiredIndex($node->value) !== null;
    }

    public function describe(Node $node): string
    {
        /** @var ArrayItem $node */
        return "removed the `required` rule from '{$node->key->value}'";
    }

    public function mutate(Node $node): Node
    {
        /** @var ArrayItem $node */
        if ($node->value instanceof String_) {
            $rules = array_values(array_filter(explode('|', $node->value->value), fn (string $rule) => $rule !== 'required'));
            $node->value = new String_($rules === [] ? 'nullable' : implode('|', $rules));

            return $node;
        }

        $index = $this->requiredIndex($node->value);
        array_splice($node->value->items, (int) $index, 1);

        if ($node->value->items === []) {
            $node->value->items[] = new ArrayItem(new String_('nullable'));
        }

        return $node;
    }

    private function requiredIndex(Array_ $array): ?int
    {
        foreach ($array->items as $index => $item) {
            if ($item?->value instanceof String_ && $item->value->value === 'required') {
                return $index;
            }
        }

        return null;
    }
}
