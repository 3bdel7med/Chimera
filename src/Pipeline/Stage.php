<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Pipeline;

interface Stage
{
    /** Do one step of the run. Call $context->halt() to stop the pipeline after this stage. */
    public function handle(MorphContext $context): void;
}
