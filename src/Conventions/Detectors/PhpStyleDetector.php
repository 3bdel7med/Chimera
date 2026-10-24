<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions\Detectors;

use Abdelhmed\Chimera\Conventions\AstQuery;
use Abdelhmed\Chimera\Conventions\Convention;
use Abdelhmed\Chimera\Conventions\ProjectCorpus;

/** strict_types and return types across app/. */
final class PhpStyleDetector implements ConventionDetector
{
    public function detect(ProjectCorpus $corpus): array
    {
        $files = $corpus->files('app');
        $found = [];

        if (count($files) >= 5) {
            $strict = count(array_filter($files, fn ($f) => AstQuery::hasStrictTypes($f->stmts)));

            if ($strict / count($files) >= 0.8) {
                $found[] = new Convention('php.strict-types', 'Start every PHP file under app/ with declare(strict_types=1);', $strict / count($files), "{$strict} of ".count($files).' files');
            }
        }

        $methods = [];
        foreach ($files as $file) {
            array_push($methods, ...array_filter(AstQuery::methods($file->stmts), fn ($m) => ! str_starts_with($m->name->toString(), '__')));
        }

        if (count($methods) >= 10) {
            $typed = count(array_filter($methods, fn ($m) => $m->returnType !== null));

            if ($typed / count($methods) >= 0.8) {
                $found[] = new Convention('php.return-types', 'Declare a return type on every method.', $typed / count($methods), "{$typed} of ".count($methods).' methods');
            }
        }

        return $found;
    }
}
