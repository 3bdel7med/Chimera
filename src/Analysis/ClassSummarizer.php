<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Analysis;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

/** Turns PHP source into a small structural summary (class, relations, fillable, method signatures). */
final class ClassSummarizer
{
    private const RELATIONS = [
        'hasOne', 'hasMany', 'belongsTo', 'belongsToMany', 'hasOneThrough', 'hasManyThrough',
        'morphOne', 'morphMany', 'morphTo', 'morphToMany', 'morphedByMany',
    ];

    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /** @return array<string, mixed>|null null when the code has no class-like declaration or does not parse */
    public function summarize(string $code, bool $withMethods = true): ?array
    {
        try {
            $stmts = $this->parser->parse($code);
        } catch (Throwable) {
            return null;
        }

        if ($stmts === null) {
            return null;
        }

        $finder = new NodeFinder();
        $class = $finder->findFirst($stmts, fn (Node $n) => $n instanceof Node\Stmt\ClassLike && $n->name !== null);

        if (! $class instanceof Node\Stmt\ClassLike || $class->name === null) {
            return null;
        }

        $namespace = $finder->findFirstInstanceOf($stmts, Node\Stmt\Namespace_::class);
        $summary = ['class' => $class->name->toString()];

        if ($namespace instanceof Node\Stmt\Namespace_ && $namespace->name !== null) {
            $summary['namespace'] = $namespace->name->toString();
        }

        if ($class instanceof Node\Stmt\Class_ && $class->extends !== null) {
            $summary['extends'] = $class->extends->toString();
        }

        $traits = [];
        foreach ($class->getTraitUses() as $use) {
            foreach ($use->traits as $trait) {
                $traits[] = $trait->toString();
            }
        }
        if ($traits !== []) {
            $summary['traits'] = $traits;
        }

        if (($fillable = $this->stringList($class, 'fillable')) !== []) {
            $summary['fillable'] = $fillable;
        }

        if (($table = $this->stringList($class, 'table', true)) !== []) {
            $summary['table'] = $table[0];
        }

        $relations = [];
        $methods = [];
        foreach ($class->getMethods() as $method) {
            $name = $method->name->toString();

            if (($relation = $this->relation($method)) !== null) {
                $relations[] = ['method' => $name] + $relation;
            }

            if ($withMethods && $method->isPublic() && ! str_starts_with($name, '__')) {
                $methods[] = $this->signature($method);
            }
        }

        if ($relations !== []) {
            $summary['relations'] = $relations;
        }
        if ($methods !== []) {
            $summary['methods'] = $methods;
        }

        return $summary;
    }

    /** @return list<string> */
    private function stringList(Node\Stmt\ClassLike $class, string $property, bool $scalar = false): array
    {
        foreach ($class->getProperties() as $prop) {
            foreach ($prop->props as $item) {
                if ($item->name->toString() !== $property || $item->default === null) {
                    continue;
                }

                if ($scalar && $item->default instanceof Node\Scalar\String_) {
                    return [$item->default->value];
                }

                if ($item->default instanceof Node\Expr\Array_) {
                    $values = [];
                    foreach ($item->default->items as $entry) {
                        if ($entry !== null && $entry->value instanceof Node\Scalar\String_) {
                            $values[] = $entry->value->value;
                        }
                    }

                    return $values;
                }
            }
        }

        return [];
    }

    /** @return array{type: string, related: string|null}|null */
    private function relation(Node\Stmt\ClassMethod $method): ?array
    {
        foreach ($method->stmts ?? [] as $stmt) {
            if (! $stmt instanceof Node\Stmt\Return_ || $stmt->expr === null) {
                continue;
            }

            $expr = $stmt->expr;
            while ($expr instanceof Node\Expr\MethodCall) {
                if (
                    $expr->var instanceof Node\Expr\Variable
                    && $expr->var->name === 'this'
                    && $expr->name instanceof Node\Identifier
                    && in_array($expr->name->toString(), self::RELATIONS, true)
                ) {
                    $first = $expr->args[0] ?? null;
                    $related = null;

                    if (
                        $first instanceof Node\Arg
                        && $first->value instanceof Node\Expr\ClassConstFetch
                        && $first->value->class instanceof Node\Name
                    ) {
                        $related = $first->value->class->getLast();
                    }

                    return ['type' => $expr->name->toString(), 'related' => $related];
                }

                $expr = $expr->var;
            }
        }

        return null;
    }

    private function signature(Node\Stmt\ClassMethod $method): string
    {
        $params = array_map(function (Node\Param $p): string {
            $name = is_string($p->var->name ?? null) ? $p->var->name : '?';

            return trim(($p->type !== null ? $this->type($p->type).' ' : '').($p->variadic ? '...' : '').'$'.$name);
        }, $method->params);

        return $method->name->toString().'('.implode(', ', $params).')'
            .($method->returnType !== null ? ': '.$this->type($method->returnType) : '');
    }

    private function type(Node $type): string
    {
        return match (true) {
            $type instanceof Node\NullableType => '?'.$this->type($type->type),
            $type instanceof Node\UnionType => implode('|', array_map(fn (Node $t) => $this->type($t), $type->types)),
            $type instanceof Node\IntersectionType => implode('&', array_map(fn (Node $t) => $this->type($t), $type->types)),
            $type instanceof Node\Name, $type instanceof Node\Identifier => $type->toString(),
            default => 'mixed',
        };
    }
}
