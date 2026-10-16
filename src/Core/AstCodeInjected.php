<?php

namespace Abdelhmed\Chimera\Core;

use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use RuntimeException;

class AstCodeInjected
{
    protected $parser;
    protected $printer;

    /**
     * Notes of the latest injection process (skipped members, merged items, etc.).
     */
    protected array $notes = [];

    /**
     * If true, returns original code silently on parse error.
     * If false (default), throws an exception and SchemaMorpher logs it as skipped.
     */
    protected bool $fallbackToOriginal = false;

    public function __construct()
    {
        // Initialize the PHP parser for host version and the standard pretty printer.
        $this->parser = (new ParserFactory())->createForHostVersion();
        $this->printer = new PrettyPrinter\Standard();
    }

    /**
     * Get notes from the last inject() operation.
     */
    public function getNotes(): array
    {
        return $this->notes;
    }

    /**
     * Safely inject and merge new code snippets into the existing file AST.
     * FIXED: Enhanced support for multi-class/trait files and safe formatting fallbacks.
     */
    public function inject(string $existingCode, string $newCodeFragment): string
    {
        $this->notes = [];

        // Safety Guard: If the fragment contains HTML/Blade tags, do not pass to PHP AST parser.
        if (str_contains($newCodeFragment, '<!DOCTYPE') || str_contains($newCodeFragment, '<html') || str_contains($newCodeFragment, '@extends') || str_contains($newCodeFragment, '@section') || str_contains($newCodeFragment, '<div')) {
            throw new RuntimeException('AstCodeInjected cannot process HTML/Blade frontend views. Use direct file writing instead.');
        }

        try {
            $existingAst = $this->parser->parse($existingCode);
            // Capture tokens immediately before parsing fragment with the same parser.
            $oldTokens = $this->parser->getTokens();

            // Safely parse the fragment snippet.
            $fragmentAst = $this->parseFragment($newCodeFragment);
        } catch (ParserError $e) {
            if ($this->fallbackToOriginal) {
                return $existingCode;
            }
            throw new RuntimeException('Could not parse the code to merge: ' . $e->getMessage());
        }

        if (!$existingAst || !$fragmentAst) {
            return $existingCode;
        }

        // 1. Extract use statements accompanying the snippet.
        $nodeFinder = new NodeFinder();
        $topUseStatements = $nodeFinder->findInstanceOf($fragmentAst, Node\Stmt\Use_::class);
        
        // 2. Extract inner class/trait/enum members (Methods, Properties, Traits, Constants).
// 2. Extract inner class/trait/enum members using ClassLike base class to avoid array type mismatch
        $classNodes = $nodeFinder->findInstanceOf($fragmentAst, Node\Stmt\ClassLike::class);        $incomingMembers = [];

        if (!empty($classNodes)) {
            $incomingMembers = $classNodes[0]->stmts;
        } else {
            $incomingMembers = array_filter($fragmentAst, fn($node) => 
                $node instanceof Node\Stmt\ClassMethod || 
                $node instanceof Node\Stmt\Property || 
                $node instanceof Node\Stmt\TraitUse ||
                $node instanceof Node\Stmt\ClassConst
            );
        }

        // Work on a cloned tree to preserve original file formatting.
        $cloner = new NodeTraverser();
        $cloner->addVisitor(new CloningVisitor());
        $clonedAst = $cloner->traverse($existingAst);

        // 3. Apply the visitor for safe engineering merge across all classes/traits in the file.
        $visitor = new class($topUseStatements, $incomingMembers) extends NodeVisitorAbstract {
            private array $topUseStatements;
            private array $incomingMembers;

            public array $notes = [];
            public bool $changed = false;

            public function __construct(array $topUseStatements, array $incomingMembers)
            {
                $this->topUseStatements = $topUseStatements;
                $this->incomingMembers = $incomingMembers;
            }

            public function beforeTraverse(array $nodes)
            {
                if (empty($this->topUseStatements)) {
                    return null;
                }

                $namespace = null;
                foreach ($nodes as $node) {
                    if ($node instanceof Node\Stmt\Namespace_) {
                        $namespace = $node;
                        break;
                    }
                }
                $list = $namespace ? $namespace->stmts : $nodes;

                $existingUses = [];
                $lastUseIndex = -1;
                foreach ($list as $index => $node) {
                    if ($node instanceof Node\Stmt\Use_) {
                        $lastUseIndex = $index;
                        foreach ($node->uses as $use) {
                            $existingUses[] = strtolower($use->name->toString());
                        }
                    } elseif ($node instanceof Node\Stmt\GroupUse) {
                        $lastUseIndex = $index;
                        foreach ($node->uses as $use) {
                            $existingUses[] = strtolower($node->prefix->toString() . '\\' . $use->name->toString());
                        }
                    }
                }

                $newUsesToInject = [];
                foreach ($this->topUseStatements as $useNode) {
                    foreach ($useNode->uses as $use) {
                        if (!in_array(strtolower($use->name->toString()), $existingUses)) {
                            $newUsesToInject[] = new Node\Stmt\Use_([$use], $useNode->type);
                            $existingUses[] = strtolower($use->name->toString());
                        }
                    }
                }

                if (!empty($newUsesToInject)) {
                    $position = $lastUseIndex + 1;
                    if ($lastUseIndex === -1) {
                        $position = 0;
                        foreach ($list as $index => $node) {
                            if ($node instanceof Node\Stmt\Declare_) {
                                $position = $index + 1;
                            }
                        }
                    }
                    array_splice($list, $position, 0, $newUsesToInject);
                    $this->changed = true;

                    if ($namespace) {
                        $namespace->stmts = $list;
                        return null;
                    }

                    return $list;
                }

                return null;
            }

            public function enterNode(Node $node)
            {
                // FIXED: Support injection into Classes, Traits, and Enums (not just the first class).
                if (($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Trait_ || $node instanceof Node\Stmt\Enum_) && $node->name !== null) {
                    
                    $traitsToInject = [];
                    $constsToInject = [];
                    $propertiesToInject = [];
                    $methodsToInject = [];

                    $knownTraits = $this->existingTraits($node);

                    foreach ($this->incomingMembers as $member) {
                        if ($member instanceof Node\Stmt\TraitUse) {
                            $member->traits = array_values(array_filter($member->traits, function ($t) use (&$knownTraits) {
                                $key = strtolower($t->toString());
                                if (in_array($key, $knownTraits, true)) {
                                    return false;
                                }
                                $knownTraits[] = $key;
                                return true;
                            }));
                            if (!empty($member->traits)) {
                                $traitsToInject[] = $member;
                            } else {
                                $this->notes[] = 'trait already used, skipped';
                            }
                        } elseif ($member instanceof Node\Stmt\ClassConst) {
                            $fresh = [];
                            foreach ($member->consts as $const) {
                                if ($this->hasConst($node, $const->name->toString())) {
                                    $this->notes[] = "constant {$const->name->toString()} already exists, skipped";
                                } else {
                                    $fresh[] = $const;
                                }
                            }
                            if (!empty($fresh)) {
                                $member->consts = $fresh;
                                $constsToInject[] = $member;
                            }
                        } elseif ($member instanceof Node\Stmt\Property) {
                            $fresh = [];
                            foreach ($member->props as $prop) {
                                $name = $prop->name->toString();
                                $existingProp = $this->findProperty($node, $name);

                                if ($existingProp === null) {
                                    $fresh[] = $prop;
                                } elseif ($this->mergeArrayDefault($existingProp, $prop)) {
                                    // Successfully merged complex/simple arrays like $fillable, $casts, etc.
                                    $this->notes[] = "property \${$name} already exists: array items merged";
                                } else {
                                    $this->notes[] = "property \${$name} already exists, skipped";
                                }
                            }
                            if (!empty($fresh)) {
                                $member->props = $fresh;
                                $propertiesToInject[] = $member;
                            }
                        } elseif ($member instanceof Node\Stmt\ClassMethod) {
                            if (!$this->hasMethod($node, $member->name->toString())) {
                                $methodsToInject[] = $member;
                            } else {
                                $this->notes[] = "method {$member->name->toString()}() already exists, skipped";
                            }
                        }
                    }

                    // 1. Inject Traits.
                    if (!empty($traitsToInject)) {
                        $lastTraitIndex = -1;
                        foreach ($node->stmts as $index => $stmt) {
                            if ($stmt instanceof Node\Stmt\TraitUse) {
                                $lastTraitIndex = $index;
                            }
                        }
                        array_splice($node->stmts, $lastTraitIndex + 1, 0, $traitsToInject);
                        $this->changed = true;
                    }

                    // 2. Inject Constants.
                    if (!empty($constsToInject)) {
                        $position = count($node->stmts);
                        foreach ($node->stmts as $index => $stmt) {
                            if ($stmt instanceof Node\Stmt\Property || $stmt instanceof Node\Stmt\ClassMethod) {
                                $position = $index;
                                break;
                            }
                        }
                        array_splice($node->stmts, $position, 0, $constsToInject);
                        $this->changed = true;
                    }

                    // 3. Inject Properties.
                    if (!empty($propertiesToInject)) {
                        $firstMethodIndex = count($node->stmts);
                        foreach ($node->stmts as $index => $stmt) {
                            if ($stmt instanceof Node\Stmt\ClassMethod) {
                                $firstMethodIndex = $index;
                                break;
                            }
                        }
                        array_splice($node->stmts, $firstMethodIndex, 0, $propertiesToInject);
                        $this->changed = true;
                    }

                    // 4. Inject Methods.
                    if (!empty($methodsToInject)) {
                        foreach ($methodsToInject as $method) {
                            $node->stmts[] = $method;
                        }
                        $this->changed = true;
                    }
                }

                return null;
            }

            private function hasMethod($classNode, string $methodName): bool
            {
                foreach ($classNode->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\ClassMethod && strtolower($stmt->name->toString()) === strtolower($methodName)) {
                        return true;
                    }
                }
                return false;
            }

            private function hasConst($classNode, string $name): bool
            {
                foreach ($classNode->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\ClassConst) {
                        foreach ($stmt->consts as $const) {
                            if ($const->name->toString() === $name) {
                                return true;
                            }
                        }
                    }
                }
                return false;
            }

            private function findProperty($classNode, string $name)
            {
                foreach ($classNode->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\Property) {
                        foreach ($stmt->props as $prop) {
                            if ($prop->name->toString() === $name) {
                                return $prop;
                            }
                        }
                    }
                }
                return null;
            }

            private function existingTraits($classNode): array
            {
                $traits = [];
                foreach ($classNode->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\TraitUse) {
                        foreach ($stmt->traits as $t) {
                            $traits[] = strtolower($t->toString());
                        }
                    }
                }
                return $traits;
            }

            /**
             * FIXED: Robust array merging for complex structures (handles string keys, values, and expressions).
             */
            private function mergeArrayDefault($existing, $incoming): bool
            {
                if (!($existing->default instanceof Node\Expr\Array_) || !($incoming->default instanceof Node\Expr\Array_)) {
                    return false;
                }

                $existingKeys = [];
                foreach ($existing->default->items as $item) {
                    if (!$item) continue;
                    if ($item->key instanceof Node\Scalar\String_) {
                        $existingKeys[$item->key->value] = true;
                    } elseif ($item->value instanceof Node\Scalar\String_) {
                        $existingKeys[$item->value->value] = true;
                    }
                }

                foreach ($incoming->default->items as $item) {
                    if (!$item) continue;
                    $keyString = null;
                    if ($item->key instanceof Node\Scalar\String_) {
                        $keyString = $item->key->value;
                    } elseif ($item->value instanceof Node\Scalar\String_) {
                        $keyString = $item->value->value;
                    }

                    if ($keyString !== null && isset($existingKeys[$keyString])) {
                        continue; // Skip duplicates
                    }

                    $existing->default->items[] = $item;
                    $this->changed = true;
                }

                return true;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);

        $modifiedAst = $traverser->traverse($clonedAst);
        $this->notes = array_merge($this->notes, $visitor->notes);

        if (!$visitor->changed) {
            if (empty($this->notes)) {
                $this->notes[] = 'no injectable members found in the fragment';
            }
            return $existingCode;
        }

        // FIXED: Safe formatting printer with robust fallback to prevent full comment loss or syntax crashes.
        try {
            return $this->printer->printFormatPreserving($modifiedAst, $existingAst, $oldTokens);
        } catch (\Throwable $e) {
            return $this->printer->prettyPrintFile($modifiedAst);
        }
    }

    /**
     * Safely parse code fragments, wrapping methods/properties in a temporary class if needed.
     * FIXED: Added support for PHP 8 Attributes and un-wrapped declarations.
     */
    protected function parseFragment(string $code): array
    {
        $code = preg_replace('/^\s*<\?php\s*/', '', $code);

        try {
            return $this->parser->parse("<?php\n" . $code) ?? [];
        } catch (ParserError $e) {
            preg_match_all('/^use\s+[^;]*\\\\[^;]*;[ \t]*$/m', $code, $matches);
            $body = trim(str_replace($matches[0], '', $code));

            return $this->parser->parse(
                "<?php\n" . implode("\n", $matches[0]) . "\nclass __ChimeraFragment {\n{$body}\n}"
            ) ?? [];
        }
    }
}