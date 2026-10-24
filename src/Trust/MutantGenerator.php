<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Trust;

use Abdelhmed\Chimera\Analysis\PhpSyntax;
use Abdelhmed\Chimera\Injection\FormatPreservingEditor;
use Abdelhmed\Chimera\Trust\Operators\MutationOperator;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Throwable;

/** Turns one PHP file into a list of mutated copies, each with exactly one deliberate bug. */
final class MutantGenerator
{
    /** @param list<MutationOperator> $operators */
    public function __construct(
        private readonly array $operators,
        private readonly FormatPreservingEditor $editor,
    ) {
    }

    /** @return list<Mutation> at most $limit, spread evenly over the file */
    public function generate(string $path, string $code, int $limit): array
    {
        $mutations = [];

        foreach ($this->spread($this->discover($code), $limit) as $candidate) {
            try {
                $mutated = $this->mutate($code, $candidate['operator'], $candidate['ordinal']);
            } catch (Throwable) {
                continue;
            }

            if ($mutated === $code || PhpSyntax::error($mutated) !== null) {
                continue;
            }

            $mutations[] = new Mutation($path, $this->operators[$candidate['operator']]->name(), $candidate['description'], $candidate['line'], $mutated);
        }

        return $mutations;
    }

    /** @return list<array{operator: int, ordinal: int, line: int, description: string}> */
    private function discover(string $code): array
    {
        try {
            $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        } catch (Throwable) {
            return [];
        }

        $collector = new CandidateCollector($this->operators);
        (new NodeTraverser($collector))->traverse($stmts);

        return $collector->found;
    }

    private function mutate(string $code, int $operator, int $ordinal): string
    {
        return $this->editor->edit($code, function (array $stmts) use ($operator, $ordinal): array {
            return (new NodeTraverser(new NthMutator($this->operators[$operator], $ordinal)))->traverse($stmts);
        });
    }

    /**
     * @param  list<array{operator: int, ordinal: int, line: int, description: string}>  $candidates
     * @return list<array{operator: int, ordinal: int, line: int, description: string}>
     */
    private function spread(array $candidates, int $limit): array
    {
        $count = count($candidates);

        if ($count <= $limit || $limit < 1) {
            return $candidates;
        }

        $picked = [];
        for ($i = 0; $i < $limit; $i++) {
            $picked[] = $candidates[(int) floor($i * $count / $limit)];
        }

        return $picked;
    }
}
