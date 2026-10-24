<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Planning;

use Abdelhmed\Chimera\Data\FileSpec;

/** Splits the blueprint into groups of files that are generated in one AI call each. */
final class BatchPlanner
{
    public function __construct(private readonly int $filesPerBatch = 4)
    {
    }

    /**
     * @param  list<FileSpec>  $specs  already ordered by dependency
     * @return list<list<FileSpec>>
     */
    public function plan(array $specs): array
    {
        return array_chunk($specs, max(1, $this->filesPerBatch));
    }
}
