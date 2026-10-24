<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Enums;

/** The steps of a morph run, as the developer sees them. */
enum Phase: string
{
    case Scan = 'scan';
    case Plan = 'plan';
    case Review = 'review';
    case Generate = 'generate';
    case Apply = 'apply';
    case Migrate = 'migrate';
    case Verify = 'verify';
    case Conventions = 'conventions';
    case Audit = 'audit';
    case Trust = 'trust';

    public function label(): string
    {
        return match ($this) {
            self::Scan => 'Scanning your project',
            self::Plan => 'Planning with the AI',
            self::Review => 'Reviewing the plan',
            self::Generate => 'Generating code',
            self::Apply => 'Applying changes',
            self::Migrate => 'Running migrations',
            self::Verify => 'Verifying with tests',
            self::Conventions => 'Loading your conventions',
            self::Audit => 'Checking your conventions',
            self::Trust => 'Stress-testing the tests',
        };
    }
}
