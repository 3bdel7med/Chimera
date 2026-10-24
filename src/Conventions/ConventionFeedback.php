<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions;

use Abdelhmed\Chimera\Data\GeneratedFile;

/** Tells the AI exactly which rules its previous answer broke, with the code it wrote. */
final class ConventionFeedback
{
    /**
     * @param  list<Violation>  $violations
     * @param  list<GeneratedFile>  $generated
     */
    public static function build(array $violations, array $generated): string
    {
        $byPath = [];
        foreach ($violations as $violation) {
            $byPath[$violation->path][] = $violation->message;
        }

        $content = [];
        foreach ($generated as $file) {
            $content[$file->path()] = $file->content;
        }

        $blocks = [];
        foreach ($byPath as $path => $messages) {
            $blocks[] = "### {$path}\nThis file breaks the project conventions:\n"
                .implode("\n", array_map(fn (string $m) => "- {$m}", $messages))
                ."\n\nYour previous version, to be corrected (return the complete file):\n```php\n".($content[$path] ?? '')."\n```";
        }

        return implode("\n\n", $blocks);
    }
}
