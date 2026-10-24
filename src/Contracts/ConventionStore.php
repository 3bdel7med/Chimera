<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Contracts;

use Abdelhmed\Chimera\Conventions\ConventionSet;

interface ConventionStore
{
    /** @return ConventionSet|null null when nothing has been learned yet */
    public function load(): ?ConventionSet;

    public function save(ConventionSet $set): void;
}
