<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Trust;

enum MutantStatus: string
{
    /** A test failed: the bug was noticed. */
    case Killed = 'killed';

    /** All tests still passed with the bug in place: the tests do not cover this behaviour. */
    case Survived = 'survived';
}
