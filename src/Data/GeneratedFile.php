<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Data;

use Abdelhmed\Chimera\Enums\FileAction;
use Abdelhmed\Chimera\Enums\FileType;

/**
 * A spec plus the code the AI wrote for it.
 * For INJECT the content is only the snippet to merge, not the whole file.
 */
final class GeneratedFile
{
    public function __construct(
        public readonly FileSpec $spec,
        public readonly string $content,
    ) {
    }

    public function path(): string
    {
        return $this->spec->path;
    }

    public function action(): FileAction
    {
        return $this->spec->action;
    }

    public function type(): FileType
    {
        return $this->spec->type;
    }
}
