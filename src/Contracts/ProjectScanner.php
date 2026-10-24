<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Contracts;

interface ProjectScanner
{
    /**
     * A compact, secret-free summary of the host project to give the model context.
     *
     * @param  (\Closure(string): void)|null  $onFile  called with the path of every file as it is analysed
     * @return array<string, mixed>
     */
    public function scan(?\Closure $onFile = null): array;
}
