<?php

use Abdelhmed\Chimera\Data\Blueprint;
use Abdelhmed\Chimera\Data\FileSpec;
use Abdelhmed\Chimera\Enums\FileAction;
use Abdelhmed\Chimera\Enums\FileType;
use Abdelhmed\Chimera\Exceptions\GenerationException;
use Abdelhmed\Chimera\Exceptions\ResponseTruncatedException;
use Abdelhmed\Chimera\Analysis\ClassSummarizer;
use Abdelhmed\Chimera\Generation\BatchPromptBuilder;
use Abdelhmed\Chimera\Generation\FileGenerator;
use Abdelhmed\Chimera\Generation\GeneratedFileValidator;
use Abdelhmed\Chimera\Injection\ClassMembersInjector;
use Abdelhmed\Chimera\Injection\FormatPreservingEditor;
use Abdelhmed\Chimera\Injection\ImportMerger;
use Abdelhmed\Chimera\Injection\InjectorRegistry;
use Abdelhmed\Chimera\Injection\RouteInjector;
use Abdelhmed\Chimera\Injection\SnippetParser;
use Abdelhmed\Chimera\Support\PromptRepository;
use Abdelhmed\Chimera\Tests\Support\FakeLlm;
use Abdelhmed\Chimera\Tests\Support\InMemoryProjectFiles;

function generator(FakeLlm $llm, InMemoryProjectFiles $files, int $retries = 2): FileGenerator
{
    $editor = new FormatPreservingEditor();
    $imports = new ImportMerger();
    $snippets = new SnippetParser();
    $registry = new InjectorRegistry(new ClassMembersInjector($editor, $imports, $snippets), new RouteInjector($editor, $imports, $snippets));

    return new FileGenerator(
        $llm,
        new PromptRepository(__DIR__.'/../../resources/prompts'),
        new BatchPromptBuilder($files, new ClassSummarizer()),
        new GeneratedFileValidator($files, $registry),
        $files,
        $retries,
    );
}

function spec(string $name): FileSpec
{
    return new FileSpec("app/Services/{$name}.php", FileType::Service, FileAction::Create, "The {$name} service");
}

function reply(string ...$names): array
{
    return ['files' => array_map(fn ($n) => ['path' => "app/Services/{$n}.php", 'content' => "<?php\n\nnamespace App\\Services;\n\nclass {$n}\n{\n}\n"], $names)];
}

$blueprint = fn () => new Blueprint('s', [spec('A'), spec('B'), spec('C'), spec('D')]);

it('generates a whole batch in one call', function () use ($blueprint) {
    $llm = new FakeLlm([reply('A', 'B')]);
    $out = generator($llm, new InMemoryProjectFiles())->generate('p', $blueprint(), [], [spec('A'), spec('B')]);

    expect($out)->toHaveCount(2);
    expect(count($llm->userPrompts))->toBe(1);
});

it('splits a batch in half when the model truncates, and keeps order', function () use ($blueprint) {
    $llm = new FakeLlm([new ResponseTruncatedException('max tokens'), reply('A', 'B'), reply('C', 'D')]);
    $out = generator($llm, new InMemoryProjectFiles())->generate('p', $blueprint(), [], [spec('A'), spec('B'), spec('C'), spec('D')]);

    expect(array_map(fn ($f) => basename($f->path()), $out))->toBe(['A.php', 'B.php', 'C.php', 'D.php']);
    expect($llm->userPrompts[2])->toContain('"class": "A"'); // second half sees the signatures of the first half
});

it('fails with a helpful message when a single file is too big', function () use ($blueprint) {
    $llm = new FakeLlm([new ResponseTruncatedException('max tokens')]);

    expect(fn () => generator($llm, new InMemoryProjectFiles())->generate('p', $blueprint(), [], [spec('A')]))
        ->toThrow(GenerationException::class);
});

it('re-requests only the files the model forgot', function () use ($blueprint) {
    $llm = new FakeLlm([reply('A'), reply('B')]);
    $out = generator($llm, new InMemoryProjectFiles())->generate('p', $blueprint(), [], [spec('A'), spec('B')]);

    expect($out)->toHaveCount(2);
    $retry = explode('## Generate exactly these files now', $llm->userPrompts[1])[1];
    expect($retry)->toContain('B.php');
    expect(str_contains($retry, 'A.php'))->toBeFalse();
});

it('asks again with the parse error when the PHP is invalid, then gives up after the limit', function () use ($blueprint) {
    $bad = ['files' => [['path' => 'app/Services/A.php', 'content' => "<?php\nclass A { public function }"]]];

    $llm = new FakeLlm([$bad, reply('A')]);
    $out = generator($llm, new InMemoryProjectFiles())->generate('p', $blueprint(), [], [spec('A')]);
    expect($out)->toHaveCount(1);
    expect($llm->userPrompts[1])->toContain('Problems with your previous answer');

    $llm = new FakeLlm([$bad, $bad, $bad]);
    expect(fn () => generator($llm, new InMemoryProjectFiles())->generate('p', $blueprint(), [], [spec('A')]))
        ->toThrow(GenerationException::class);
});
