<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions;

/** Decides whether one habit clearly dominates the others (and is therefore a convention, not a coincidence). */
final class Dominance
{
    /**
     * @param  array<string, int>  $counts
     * @return array{key: string, share: float, count: int, total: int}|null
     */
    public static function of(array $counts, int $minSample = 3, float $threshold = 0.7): ?array
    {
        $total = array_sum($counts);

        if ($total < $minSample) {
            return null;
        }

        arsort($counts);
        $key = (string) array_key_first($counts);
        $share = $counts[$key] / $total;

        return $share >= $threshold ? ['key' => $key, 'share' => $share, 'count' => $counts[$key], 'total' => $total] : null;
    }
}
