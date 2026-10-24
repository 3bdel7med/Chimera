<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Injection;

use Abdelhmed\Chimera\Contracts\Injector;
use Abdelhmed\Chimera\Exceptions\InjectionException;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/**
 * Adds new methods, properties, constants and traits (plus their imports) to an existing class.
 * The snippet is a class skeleton holding only the new members; existing members are never replaced,
 * except that list-style properties such as $fillable are extended with the missing entries.
 */
final class ClassMembersInjector implements Injector
{
    public function __construct(
        private readonly FormatPreservingEditor $editor,
        private readonly ImportMerger $imports,
        private readonly SnippetParser $snippets,
    ) {
    }

    public function inject(string $existing, string $snippet): string
    {
        $parsed = $this->snippets->parse($snippet);
        $finder = new NodeFinder();

        $source = $finder->findFirst($parsed, fn (Node $n) => $n instanceof Stmt\Class_ || $n instanceof Stmt\Trait_);

        if (! $source instanceof Stmt\ClassLike) {
            throw new InjectionException('The snippet must be a class skeleton containing the new members.');
        }

        /** @var list<Stmt\Use_> $uses */
        $uses = $finder->findInstanceOf($parsed, Stmt\Use_::class);

        $added = [];

        $code = $this->editor->edit($existing, function (array $stmts) use ($source, $uses, $finder, &$added): array {
            $target = $finder->findFirst($stmts, fn (Node $n) => $n instanceof Stmt\Class_ || $n instanceof Stmt\Trait_);

            if (! $target instanceof Stmt\ClassLike) {
                throw new InjectionException('The target file does not contain a class to inject into.');
            }

            foreach ($source->stmts as $member) {
                if ($member instanceof Stmt\ClassMethod && $target->getMethod($member->name->toString()) === null) {
                    $added[] = $member->name->toString();
                }

                $this->addMember($target, $member);
            }

            return $this->mergeImports($stmts, $uses);
        });

        return $this->separateAddedMethods($code, $added);
    }

    /** The format-preserving printer glues new methods to the previous one; put a blank line between them. */
    private function separateAddedMethods(string $code, array $names): string
    {
        foreach ($names as $name) {
            $pattern = '/([^\n]*[^\n{\s][ \t]*\n)((?:[ \t]*\/\*\*(?:(?!\*\/).)*\*\/\n)?(?:[ \t]*#\[[^\n]*\n)*'
                .'[ \t]+(?:(?:public|protected|private|static|final|abstract)\s+)+function\s+'.preg_quote($name, '/').'\s*\()/s';

            $code = (string) preg_replace($pattern, "$1\n$2", $code, 1);
        }

        return $code;
    }

    private function addMember(Stmt\ClassLike $target, Stmt $member): void
    {
        match (true) {
            $member instanceof Stmt\ClassMethod => $this->addMethod($target, $member),
            $member instanceof Stmt\Property => $this->addProperty($target, $member),
            $member instanceof Stmt\TraitUse => $this->addTraits($target, $member),
            $member instanceof Stmt\ClassConst => $this->addConstant($target, $member),
            default => $target->stmts[] = $member,
        };
    }

    private function addMethod(Stmt\ClassLike $target, Stmt\ClassMethod $method): void
    {
        if ($target->getMethod($method->name->toString()) === null) {
            $target->stmts[] = $method;
        }
    }

    private function addProperty(Stmt\ClassLike $target, Stmt\Property $property): void
    {
        $existing = $target->getProperty($property->props[0]->name->toString());

        if ($existing === null) {
            $this->insertInHeader($target, $property);

            return;
        }

        $this->mergeListProperty($existing, $property);
    }

    private function mergeListProperty(Stmt\Property $existing, Stmt\Property $incoming): void
    {
        $current = $existing->props[0]->default;
        $wanted = $incoming->props[0]->default;

        if (! $current instanceof Node\Expr\Array_ || ! $wanted instanceof Node\Expr\Array_) {
            return;
        }

        $printer = new Standard();
        $signature = fn (Node\ArrayItem $item): string => ($item->key !== null ? $printer->prettyPrintExpr($item->key).'=>' : '')
            .$printer->prettyPrintExpr($item->value);

        $present = [];
        foreach ($current->items as $item) {
            if ($item !== null) {
                $present[$signature($item)] = true;
            }
        }

        foreach ($wanted->items as $item) {
            if ($item !== null && ! isset($present[$signature($item)])) {
                $current->items[] = $item;
            }
        }
    }

    private function addTraits(Stmt\ClassLike $target, Stmt\TraitUse $incoming): void
    {
        $existingUses = $target->getTraitUses();
        $present = [];

        foreach ($existingUses as $use) {
            foreach ($use->traits as $trait) {
                $present[strtolower($trait->toString())] = true;
            }
        }

        foreach ($incoming->traits as $trait) {
            if (isset($present[strtolower($trait->toString())])) {
                continue;
            }

            if ($existingUses !== []) {
                $existingUses[0]->traits[] = $trait;
            } else {
                $existingUses = [new Stmt\TraitUse([$trait])];
                array_unshift($target->stmts, $existingUses[0]);
            }
        }
    }

    private function addConstant(Stmt\ClassLike $target, Stmt\ClassConst $const): void
    {
        $name = $const->consts[0]->name->toString();

        foreach ($target->getConstants() as $existing) {
            foreach ($existing->consts as $item) {
                if ($item->name->toString() === $name) {
                    return;
                }
            }
        }

        $this->insertInHeader($target, $const);
    }

    /** Traits, constants and properties live at the top of the class body, before the methods. */
    private function insertInHeader(Stmt\ClassLike $target, Stmt $new): void
    {
        $position = 0;

        foreach ($target->stmts as $index => $stmt) {
            if ($stmt instanceof Stmt\TraitUse || $stmt instanceof Stmt\ClassConst || $stmt instanceof Stmt\Property) {
                $position = $index + 1;
            }
        }

        array_splice($target->stmts, $position, 0, [$new]);
    }

    /**
     * @param  list<Stmt>  $stmts
     * @param  list<Stmt\Use_>  $uses
     * @return list<Stmt>
     */
    private function mergeImports(array $stmts, array $uses): array
    {
        if ($uses === []) {
            return $stmts;
        }

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                $stmt->stmts = $this->imports->merge($stmt->stmts, $uses);

                return $stmts;
            }
        }

        return $this->imports->merge($stmts, $uses);
    }
}
