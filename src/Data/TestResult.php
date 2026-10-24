<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Data;

final class TestResult
{
    private function __construct(
        public readonly bool $ran,
        public readonly bool $passed,
        public readonly string $output,
        public readonly ?string $note = null,
    ) {
    }

    public static function pass(string $output = ''): self
    {
        return new self(true, true, $output);
    }

    public static function fail(string $output): self
    {
        return new self(true, false, $output);
    }

    public static function skip(string $reason): self
    {
        return new self(false, false, '', $reason);
    }

    public function hasFailed(): bool
    {
        return $this->ran && ! $this->passed;
    }
}
