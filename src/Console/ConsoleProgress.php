<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Console;

use Abdelhmed\Chimera\Contracts\ProgressTracker;
use Abdelhmed\Chimera\Data\FileSpec;
use Abdelhmed\Chimera\Enums\FileState;
use Abdelhmed\Chimera\Enums\Phase;
use Closure;

/**
 * Append-only progress output, so it reads the same in a terminal, in VS Code, in CI logs and over SSH:
 *
 *   ▸ Scanning your project
 *   ✔ Scanning your project  14 files analysed (0.4s)
 *
 * On a real terminal, detail() additionally shows a one-line status that is overwritten in place.
 */
final class ConsoleProgress implements ProgressTracker
{
    /** @var array<string, array{state: FileState, note: string}> */
    private array $files = [];

    /** @var array<string, float> */
    private array $startedAt = [];

    private bool $transient = false;

    /**
     * @param  Closure(string): mixed  $write
     * @param  Closure(): float  $clock  seconds, injectable for tests
     */
    public function __construct(
        private readonly Closure $write,
        private readonly bool $ansi = false,
        private readonly ?Closure $clock = null,
        private readonly TreeRenderer $tree = new TreeRenderer(),
    ) {
    }

    public function phase(Phase $phase, ?string $detail = null): void
    {
        $this->startedAt[$phase->value] = $this->now();
        $this->line($this->paint('▸', '36').' '.$this->paint($phase->label(), '1').($detail !== null ? '  '.$this->paint($detail, '2') : ''));
    }

    public function phaseDone(Phase $phase, ?string $detail = null): void
    {
        $elapsed = $this->now() - ($this->startedAt[$phase->value] ?? $this->now());

        $this->line(
            $this->paint('✔', '32').' '.$phase->label()
            .($detail !== null ? '  '.$detail : '')
            .'  '.$this->paint(sprintf('(%.1fs)', $elapsed), '2')
        );
    }

    public function detail(string $message): void
    {
        if (! $this->ansi) {
            return;
        }

        ($this->write)("\r\033[K  ".$this->paint($message, '2'));
        $this->transient = true;
    }

    public function plan(array $specs): void
    {
        foreach ($specs as $spec) {
            $this->files[$spec->path] = ['state' => FileState::Pending, 'note' => $this->describe($spec)];
        }

        $this->printTree();
    }

    public function file(string $path, FileState $state, ?string $note = null): void
    {
        $previous = $this->files[$path]['note'] ?? '';
        $note ??= '';

        if ($state === FileState::Healed) {
            $note = trim($previous.' · fixed by the AI', ' ·');
        }

        $this->files[$path] = ['state' => $state, 'note' => $note !== '' ? $note : $previous];

        if ($state !== FileState::Pending && $state !== FileState::Generating) {
            $this->line('  '.$this->symbol($state).' '.str_pad($state->verb(), 9).' '.$path.($note !== '' ? '  '.$this->paint($note, '2') : ''));
        }
    }

    public function summary(): void
    {
        $this->printTree();
    }

    private function printTree(): void
    {
        $leaves = [];
        foreach ($this->files as $path => $entry) {
            $leaves[$path] = $this->symbol($entry['state']).' '.basename($path).($entry['note'] !== '' ? '  '.$this->paint($entry['note'], '2') : '');
        }

        $this->line('');
        foreach ($this->tree->render($leaves, fn (string $dir) => $this->paint($dir, '34')) as $row) {
            $this->line($row);
        }
        $this->line('');
    }

    private function describe(FileSpec $spec): string
    {
        $text = $spec->type->value.' · '.$spec->action->value;

        return $spec->description !== '' ? $text.' — '.mb_strimwidth($spec->description, 0, 60, '…') : $text;
    }

    private function symbol(FileState $state): string
    {
        return $this->paint($state->symbol(), match ($state) {
            FileState::Pending => '33',
            FileState::Generating => '36',
            FileState::Generated => '36',
            FileState::Written, FileState::Healed => '32',
            FileState::Unchanged => '2',
        });
    }

    private function line(string $text): void
    {
        ($this->write)(($this->transient ? "\r\033[K" : '').$text."\n");
        $this->transient = false;
    }

    private function paint(string $text, string $code): string
    {
        return $this->ansi ? "\033[{$code}m{$text}\033[0m" : $text;
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }
}
