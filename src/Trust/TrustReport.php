<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Trust;

/** How good the generated tests are: the share of deliberate bugs they noticed. */
final class TrustReport
{
    /** @param list<MutantResult> $results */
    public function __construct(
        public readonly array $results = [],
        public readonly ?string $skippedReason = null,
    ) {
    }

    public static function skipped(string $reason): self
    {
        return new self([], $reason);
    }

    public function wasSkipped(): bool
    {
        return $this->skippedReason !== null;
    }

    public function total(): int
    {
        return count($this->results);
    }

    public function killed(): int
    {
        return count(array_filter($this->results, fn (MutantResult $r) => $r->status === MutantStatus::Killed));
    }

    /** @return list<MutantResult> */
    public function survivors(): array
    {
        return array_values(array_filter($this->results, fn (MutantResult $r) => $r->status === MutantStatus::Survived));
    }

    /** 0-100, or null when nothing could be measured. */
    public function score(): ?int
    {
        return $this->total() === 0 ? null : (int) round(100 * $this->killed() / $this->total());
    }
}
