<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Contracts;

use Abdelhmed\Chimera\Exceptions\InjectionException;

interface Injector
{
    /**
     * Merge $snippet into $existing and return the new file content.
     * Must be idempotent: injecting the same snippet twice changes nothing.
     *
     * @throws InjectionException
     */
    public function inject(string $existing, string $snippet): string;
}
