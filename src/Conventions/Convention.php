<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions;

/** One habit of the project, written as an instruction the AI must follow. */
final class Convention
{
    public function __construct(
        public readonly string $id,
        public readonly string $rule,
        public readonly float $confidence = 1.0,
        public readonly string $evidence = '',
        public readonly bool $enabled = true,
        /** "learned" by Chimera, or "custom" when a developer wrote it into the file by hand. */
        public readonly string $source = 'learned',
    ) {
    }

    public function withEnabled(bool $enabled): self
    {
        return new self($this->id, $this->rule, $this->confidence, $this->evidence, $enabled, $this->source);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['rule'],
            (float) ($data['confidence'] ?? 1.0),
            (string) ($data['evidence'] ?? ''),
            (bool) ($data['enabled'] ?? true),
            (string) ($data['source'] ?? 'learned'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'rule' => $this->rule,
            'confidence' => round($this->confidence, 2),
            'evidence' => $this->evidence,
            'enabled' => $this->enabled,
            'source' => $this->source,
        ];
    }
}
