<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Injection;

use Abdelhmed\Chimera\Exceptions\InjectionException;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Throwable;

/** Edits PHP source through the AST while keeping the formatting of everything that was not changed. */
final class FormatPreservingEditor
{
    /**
     * @param  callable(list<Stmt>): list<Stmt>  $mutator  receives the cloned statements and returns the new list
     */
    public function edit(string $code, callable $mutator): string
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $oldStmts = $parser->parse($code);
            $oldTokens = $parser->getTokens();
        } catch (Throwable $e) {
            throw new InjectionException('The existing file could not be parsed: '.$e->getMessage(), 0, $e);
        }

        $newStmts = (new NodeTraverser(new CloningVisitor()))->traverse($oldStmts ?? []);
        $newStmts = $mutator($newStmts);

        return (new Standard())->printFormatPreserving($newStmts, $oldStmts ?? [], $oldTokens);
    }
}
