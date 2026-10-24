<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Pipeline;

/** Runs the stages in order and stops as soon as one of them halts the context. */
final class MorphPipeline
{
    /** @param list<Stage> $stages */
    public function __construct(private readonly array $stages)
    {
    }

    public function run(MorphContext $context): MorphContext
    {
        foreach ($this->stages as $stage) {
            if ($context->isHalted()) {
                break;
            }

            $stage->handle($context);
        }

        return $context;
    }
}
