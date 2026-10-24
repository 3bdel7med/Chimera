<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Injection;

use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Use_;

/** Adds `use` imports to a statement list without duplicating or clashing with existing ones. */
final class ImportMerger
{
    /**
     * @param  list<Stmt>  $stmts  top-level statements, or the body of a namespace
     * @param  list<Use_>  $incoming
     * @return list<Stmt>
     */
    public function merge(array $stmts, array $incoming): array
    {
        $taken = [];
        $lastUse = -1;

        foreach ($stmts as $index => $stmt) {
            if ($stmt instanceof Use_) {
                $lastUse = $index;
                foreach ($stmt->uses as $item) {
                    $taken[$this->key($stmt->type, $item->alias?->toString() ?? $item->name->getLast())] = true;
                }
            }
        }

        $new = [];
        foreach ($incoming as $use) {
            foreach ($use->uses as $item) {
                $key = $this->key($use->type, $item->alias?->toString() ?? $item->name->getLast());

                if (isset($taken[$key])) {
                    continue;
                }

                $taken[$key] = true;
                $new[] = new Use_([$item], $use->type);
            }
        }

        if ($new === []) {
            return $stmts;
        }

        $position = $lastUse >= 0 ? $lastUse + 1 : $this->afterDeclares($stmts);
        array_splice($stmts, $position, 0, $new);

        return $stmts;
    }

    private function key(int $type, string $alias): string
    {
        return $type.':'.strtolower($alias);
    }

    /** @param list<Stmt> $stmts */
    private function afterDeclares(array $stmts): int
    {
        $position = 0;

        foreach ($stmts as $index => $stmt) {
            if ($stmt instanceof Declare_) {
                $position = $index + 1;
            }
        }

        return $position;
    }
}
