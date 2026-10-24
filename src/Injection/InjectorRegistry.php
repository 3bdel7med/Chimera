<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Injection;

use Abdelhmed\Chimera\Contracts\Injector;
use Abdelhmed\Chimera\Data\FileSpec;
use Abdelhmed\Chimera\Enums\FileType;

final class InjectorRegistry
{
    public function __construct(
        private readonly ClassMembersInjector $members,
        private readonly RouteInjector $routes,
    ) {
    }

    public function for(FileSpec $spec): Injector
    {
        return $spec->type === FileType::Route || str_starts_with($spec->path, 'routes/')
            ? $this->routes
            : $this->members;
    }
}
