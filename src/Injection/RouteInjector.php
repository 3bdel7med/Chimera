<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Injection;

use Abdelhmed\Chimera\Contracts\Injector;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;

/** Appends new route statements to a routes file, skipping any statement that is already there. */
final class RouteInjector implements Injector
{
    public function __construct(
        private readonly FormatPreservingEditor $editor,
        private readonly ImportMerger $imports,
        private readonly SnippetParser $snippets,
    ) {
    }

    public function inject(string $existing, string $snippet): string
    {
        $incoming = $this->snippets->parse($snippet);
        $uses = array_values(array_filter($incoming, fn (Stmt $s) => $s instanceof Stmt\Use_));
        $routes = array_values(array_filter(
            $incoming,
            fn (Stmt $s) => ! $s instanceof Stmt\Use_ && ! $s instanceof Stmt\Declare_ && ! $s instanceof Stmt\Nop
        ));

        $printer = new Standard();
        $fingerprint = fn (Stmt $s): string => (string) preg_replace('/\s+/', '', $printer->prettyPrint([$s]));

        return $this->editor->edit($existing, function (array $stmts) use ($routes, $uses, $fingerprint): array {
            $present = [];
            foreach ($stmts as $stmt) {
                $present[$fingerprint($stmt)] = true;
            }

            foreach ($routes as $route) {
                if (! isset($present[$fingerprint($route)])) {
                    $present[$fingerprint($route)] = true;
                    $stmts[] = $route;
                }
            }

            return $this->imports->merge($stmts, $uses);
        });
    }
}
