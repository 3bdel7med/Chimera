<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Injection;

use Abdelhmed\Chimera\Exceptions\InjectionException;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use Throwable;

final class SnippetParser
{
    /**
     * @return list<Stmt>
     *
     * @throws InjectionException
     */
    public function parse(string $snippet): array
    {
        if (! str_starts_with(ltrim($snippet), '<?php')) {
            $snippet = "<?php\n".ltrim($snippet);
        }

        try {
            return (new ParserFactory())->createForNewestSupportedVersion()->parse($snippet) ?? [];
        } catch (Throwable $e) {
            throw new InjectionException('The injected snippet is not valid PHP: '.$e->getMessage(), 0, $e);
        }
    }
}
