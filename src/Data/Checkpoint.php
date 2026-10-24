<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Data;

/** What one morph run touched, so it can be undone. */
final class Checkpoint
{
    /**
     * @param  list<string>  $created  files that did not exist before the run
     * @param  list<string>  $modified  files whose original content was backed up
     * @param  list<string>  $migrations  migration files that were executed
     */
    public function __construct(
        public readonly string $id,
        public readonly string $prompt,
        public readonly string $createdAt,
        public readonly array $created = [],
        public readonly array $modified = [],
        public readonly array $migrations = [],
        public readonly ?string $rolledBackAt = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) ($data['prompt'] ?? ''),
            (string) ($data['created_at'] ?? ''),
            array_values((array) ($data['created'] ?? [])),
            array_values((array) ($data['modified'] ?? [])),
            array_values((array) ($data['migrations'] ?? [])),
            isset($data['rolled_back_at']) ? (string) $data['rolled_back_at'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'created_at' => $this->createdAt,
            'created' => $this->created,
            'modified' => $this->modified,
            'migrations' => $this->migrations,
            'rolled_back_at' => $this->rolledBackAt,
        ];
    }
}
