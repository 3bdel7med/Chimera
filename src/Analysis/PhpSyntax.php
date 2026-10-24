<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Analysis;

use PhpParser\ParserFactory;
use Throwable;

final class PhpSyntax
{
    /** @return string|null the parse error, or null when the code is valid PHP source */
    public static function error(string $code): ?string
    {
        if (! str_starts_with(ltrim($code), '<?php')) {
            return 'PHP files must start with <?php';
        }

        try {
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }
}
