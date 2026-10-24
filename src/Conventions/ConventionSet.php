<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions;

final class ConventionSet
{
    /** @param list<Convention> $conventions */
    public function __construct(
        public readonly array $conventions = [],
        public readonly int $filesAnalysed = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->conventions === [];
    }

    /** @return list<Convention> */
    public function enabled(): array
    {
        return array_values(array_filter($this->conventions, fn (Convention $c) => $c->enabled));
    }

    /** @return list<string> the rules of the enabled conventions, ready for a prompt */
    public function rules(): array
    {
        return array_map(fn (Convention $c) => $c->rule, $this->enabled());
    }

    /** @return list<string> */
    public function enabledIds(): array
    {
        return array_map(fn (Convention $c) => $c->id, $this->enabled());
    }

    /**
     * Combine freshly learned conventions with what the developer already curated:
     * conventions they switched off stay off, and conventions they wrote by hand are kept.
     */
    public function mergeWith(?self $previous): self
    {
        if ($previous === null) {
            return $this->sorted($this->conventions);
        }

        $before = [];
        foreach ($previous->conventions as $convention) {
            $before[$convention->id] = $convention;
        }

        $merged = [];
        foreach ($this->conventions as $convention) {
            $old = $before[$convention->id] ?? null;
            $merged[] = $old !== null && ! $old->enabled ? $convention->withEnabled(false) : $convention;
        }

        $learned = array_flip(array_map(fn (Convention $c) => $c->id, $this->conventions));
        foreach ($previous->conventions as $convention) {
            if ($convention->source === 'custom' && ! isset($learned[$convention->id])) {
                $merged[] = $convention;
            }
        }

        return $this->sorted($merged);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(fn (array $c) => Convention::fromArray($c), array_values(array_filter((array) ($data['conventions'] ?? []), 'is_array'))),
            (int) ($data['files_analysed'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'files_analysed' => $this->filesAnalysed,
            'conventions' => array_map(fn (Convention $c) => $c->toArray(), $this->conventions),
        ];
    }

    /** @param list<Convention> $conventions */
    private function sorted(array $conventions): self
    {
        usort($conventions, fn (Convention $a, Convention $b) => strcmp($a->id, $b->id));

        return new self($conventions, $this->filesAnalysed);
    }
}
