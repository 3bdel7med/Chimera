<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions;

use Abdelhmed\Chimera\Contracts\ProjectFiles;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

/** Parses the project's PHP files once and shares the ASTs between all detectors. */
final class ProjectCorpus
{
    /** @var array<string, ParsedFile|null> */
    private array $parsed = [];

    private Parser $parser;

    public function __construct(
        private readonly ProjectFiles $files,
        private readonly int $maxFilesPerDirectory = 400,
    ) {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /** @return list<ParsedFile> */
    public function files(string $directory): array
    {
        $result = [];

        foreach (array_slice($this->files->list($directory), 0, $this->maxFilesPerDirectory) as $path) {
            if (($file = $this->parse($path)) !== null) {
                $result[] = $file;
            }
        }

        return $result;
    }

    public function exists(string $path): bool
    {
        return $this->files->exists($path);
    }

    public function analysed(): int
    {
        return count(array_filter($this->parsed));
    }

    private function parse(string $path): ?ParsedFile
    {
        if (array_key_exists($path, $this->parsed)) {
            return $this->parsed[$path];
        }

        try {
            $stmts = $this->parser->parse($this->files->get($path));

            return $this->parsed[$path] = $stmts === null ? null : new ParsedFile($path, $stmts);
        } catch (Throwable) {
            return $this->parsed[$path] = null;
        }
    }
}
