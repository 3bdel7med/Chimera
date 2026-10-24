<?php

use Abdelhmed\Chimera\Analysis\AstProjectScanner;
use Abdelhmed\Chimera\Analysis\ClassSummarizer;
use Abdelhmed\Chimera\Analysis\RouteSummarizer;
use Abdelhmed\Chimera\Application\ChangeApplier;
use Abdelhmed\Chimera\Checkpoint\FilesystemCheckpointStore;
use Abdelhmed\Chimera\Checkpoint\RollbackService;
use Abdelhmed\Chimera\Console\ConsoleProgress;
use Abdelhmed\Chimera\Conventions\Checks;
use Abdelhmed\Chimera\Conventions\ConventionChecker;
use Abdelhmed\Chimera\Conventions\ConventionLearner;
use Abdelhmed\Chimera\Conventions\Detectors;
use Abdelhmed\Chimera\Conventions\FilesystemConventionStore;
use Abdelhmed\Chimera\Data\TestResult;
use Abdelhmed\Chimera\Generation\BatchPromptBuilder;
use Abdelhmed\Chimera\Generation\FileGenerator;
use Abdelhmed\Chimera\Generation\GeneratedFileValidator;
use Abdelhmed\Chimera\Injection\ClassMembersInjector;
use Abdelhmed\Chimera\Injection\FormatPreservingEditor;
use Abdelhmed\Chimera\Injection\ImportMerger;
use Abdelhmed\Chimera\Injection\InjectorRegistry;
use Abdelhmed\Chimera\Injection\RouteInjector;
use Abdelhmed\Chimera\Injection\SnippetParser;
use Abdelhmed\Chimera\Pipeline\MorphContext;
use Abdelhmed\Chimera\Pipeline\MorphPipeline;
use Abdelhmed\Chimera\Pipeline\Stages as S;
use Abdelhmed\Chimera\Planning\BatchPlanner;
use Abdelhmed\Chimera\Planning\BlueprintNormalizer;
use Abdelhmed\Chimera\Planning\BlueprintParser;
use Abdelhmed\Chimera\Planning\BlueprintPlanner;
use Abdelhmed\Chimera\Support\NullReporter;
use Abdelhmed\Chimera\Support\PathGuard;
use Abdelhmed\Chimera\Support\PromptRepository;
use Abdelhmed\Chimera\Tests\Support\FakeLlm;
use Abdelhmed\Chimera\Tests\Support\FakeMigrationRunner;
use Abdelhmed\Chimera\Tests\Support\FakeTestRunner;
use Abdelhmed\Chimera\Tests\Support\InMemoryProjectFiles;
use Abdelhmed\Chimera\Tests\Support\SampleProject;
use Abdelhmed\Chimera\Trust\FilesystemMutationRecovery;
use Abdelhmed\Chimera\Trust\MutantGenerator;
use Abdelhmed\Chimera\Trust\MutationRunner;
use Abdelhmed\Chimera\Trust\Operators;
use Abdelhmed\Chimera\Trust\TrustAnalyzer;
use Abdelhmed\Chimera\Verification\HealingService;

function qualityPipeline(FakeLlm $llm, FakeTestRunner $tests, InMemoryProjectFiles $files, ConsoleProgress $progress, string $dir): MorphPipeline
{
    $editor = new FormatPreservingEditor();
    $registry = new InjectorRegistry(new ClassMembersInjector($editor, new ImportMerger(), new SnippetParser()), new RouteInjector($editor, new ImportMerger(), new SnippetParser()));
    $guard = new PathGuard(['app', 'routes', 'database', 'tests']);
    $summarizer = new ClassSummarizer();
    $prompts = new PromptRepository(__DIR__.'/../../resources/prompts');
    $store = new FilesystemCheckpointStore($dir.'/checkpoints');
    $reporter = new NullReporter();
    $migrations = new FakeMigrationRunner();
    $recovery = new FilesystemMutationRecovery($dir.'/mutation-recovery.json');

    $generator = new FileGenerator($llm, $prompts, new BatchPromptBuilder($files, $summarizer), new GeneratedFileValidator($files, $registry), $files, 1);
    $applier = new ChangeApplier($files, $guard, $registry, $store);
    $learner = new ConventionLearner($files, [
        new Detectors\ValidationDetector(), new Detectors\ResponseDetector(), new Detectors\ArchitectureDetector(), new Detectors\ModelDetector(),
        new Detectors\PhpStyleDetector(), new Detectors\TestingDetector(), new Detectors\RoutingDetector(), new Detectors\MigrationDetector(),
    ]);
    $checker = new ConventionChecker([
        new Checks\FormRequestCheck(), new Checks\NoQueriesInControllersCheck(), new Checks\StrictTypesCheck(),
        new Checks\ReturnTypesCheck(), new Checks\PestStyleCheck(), new Checks\PhpUnitStyleCheck(),
    ], $files);
    $mutants = new MutantGenerator([
        new Operators\ComparisonOperator(), new Operators\LogicalOperator(), new Operators\NegationOperator(),
        new Operators\StatusCodeOperator(), new Operators\RequiredRuleOperator(), new Operators\AuthorizationOperator(),
    ], $editor);

    return new MorphPipeline([
        new S\RecoverInterruptedMutationsStage($recovery, $files, $reporter),
        new S\ScanProjectStage(new AstProjectScanner($files, $summarizer, new RouteSummarizer()), $progress),
        new S\LoadConventionsStage(new FilesystemConventionStore($dir.'/conventions.json'), $learner, $progress, $reporter),
        new S\PlanBlueprintStage(new BlueprintPlanner($llm, $prompts, new BlueprintParser(), new BlueprintNormalizer($guard, $files)), $reporter, $progress),
        new S\ConfirmBlueprintStage($reporter, $progress),
        new S\GenerateFilesStage($generator, new BatchPlanner(2), $reporter, $progress),
        new S\ConventionAuditStage($checker, $generator, $reporter, $progress, 1),
        new S\ApplyChangesStage($applier, $store, new RollbackService($files, $store, $migrations), $reporter, $progress),
        new S\MigrateStage($migrations, $store, $reporter, $progress),
        new S\VerifyAndHealStage(new HealingService($tests, $generator, $applier, 2), $reporter, $progress),
        new S\TrustScoreStage(new TrustAnalyzer($files, $mutants, new MutationRunner($files, $tests, $recovery), 20), $progress),
        new S\SummaryStage($reporter, $progress),
    ]);
}

const NOTE_REQUEST = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Http\\Requests;\n\nclass StoreNoteRequest\n{\n    public function rules(): array\n    {\n        return ['title' => 'required|string'];\n    }\n}\n";

const NOTE_CONTROLLER_BAD = "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass NoteController\n{\n    public function store(\$request)\n    {\n        \$request->validate(['title' => 'required']);\n\n        return response()->json([], 201);\n    }\n}\n";

const NOTE_CONTROLLER_GOOD = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Http\\Controllers;\n\nuse Illuminate\\Http\\JsonResponse;\n\nclass NoteController\n{\n    public function store(StoreNoteRequest \$request, Note \$note): JsonResponse\n    {\n        if (\$note->user_id !== \$request->user()->id) {\n            abort(403);\n        }\n\n        return response()->json(\$request->validated(), 201);\n    }\n}\n";

it('teaches the AI the project style, repairs code that breaks it before writing, then stress-tests the tests', function () {
    $files = new InMemoryProjectFiles(SampleProject::files());

    $llm = new FakeLlm([
        ['summary' => 'Notes', 'files' => [
            ['path' => 'app/Http/Requests/StoreNoteRequest.php', 'type' => 'request', 'action' => 'CREATE', 'description' => 'validates title'],
            ['path' => 'app/Http/Controllers/NoteController.php', 'type' => 'controller', 'action' => 'CREATE', 'description' => 'store a note'],
            ['path' => 'tests/Feature/NoteTest.php', 'type' => 'test', 'action' => 'CREATE', 'description' => 'store test'],
        ]],
        ['files' => [
            ['path' => 'app/Http/Requests/StoreNoteRequest.php', 'content' => NOTE_REQUEST],
            ['path' => 'app/Http/Controllers/NoteController.php', 'content' => NOTE_CONTROLLER_BAD],
        ]],
        ['files' => [['path' => 'tests/Feature/NoteTest.php', 'content' => "<?php\n\nit('stores a note', function () {\n    expect(true)->toBeTrue();\n});\n"]]],
        ['files' => [['path' => 'app/Http/Controllers/NoteController.php', 'content' => NOTE_CONTROLLER_GOOD]]], // the repair
    ]);

    // baseline passes; then 4 deliberate bugs: caught, caught, NOT caught, caught
    $tests = new FakeTestRunner([TestResult::pass(), TestResult::fail('x'), TestResult::fail('x'), TestResult::pass(), TestResult::fail('x')]);

    $screen = '';
    $progress = new ConsoleProgress(function (string $t) use (&$screen) { $screen .= $t; }, false, fn () => 0.0);
    $context = qualityPipeline($llm, $tests, $files, $progress, $this->dir)->run(new MorphContext('notes feature', assumeYes: true));

    // 1. the AI was told the project's conventions, in the plan and in every code request
    expect($llm->userPrompts[0])->toContain('## Project conventions');
    expect($llm->userPrompts[0])->toContain('Form Request');
    expect($llm->userPrompts[1])->toContain('Start every PHP file under app/ with declare(strict_types=1);');
    expect($llm->userPrompts[1])->not->toContain('"conventions"');

    // 2. the first controller broke the rules, so it was sent back with exactly what was wrong, and only that file
    expect(count($llm->userPrompts))->toBe(4);
    expect($llm->userPrompts[3])->toContain('NoteController::store() validates inline');
    expect($llm->userPrompts[3])->toContain('does not start with declare(strict_types=1)');
    $retry = explode('## Generate exactly these files now', $llm->userPrompts[3])[1];
    expect(explode('## Problems', $retry)[0])->not->toContain('StoreNoteRequest');

    // 3. only the corrected controller reached the disk
    expect($files->files['app/Http/Controllers/NoteController.php'])->toBe(rtrim(NOTE_CONTROLLER_GOOD)."\n");
    expect($context->conventionWarnings)->toBe([]);

    // 4. trust score: 3 of 4 deliberate bugs caught, and the one that went unnoticed is named
    expect($context->trust->score())->toBe(75);
    expect($context->trust->survivors())->toHaveCount(1);
    expect($context->trust->survivors()[0]->label())->toContain('status code 403 to 404');
    expect($files->files['app/Http/Requests/StoreNoteRequest.php'])->toBe(rtrim(NOTE_REQUEST)."\n"); // every bug was undone

    // 5. the developer saw the story
    foreach (['✔ Loading your conventions', '✔ Checking your conventions  fixed 3 violation(s) in 1 file(s) before writing anything',
        '✔ Stress-testing the tests  trust score 75% (3 of 4 bugs caught)'] as $line) {
        expect($screen)->toContain($line);
    }
});

it('does not stress-test tests that do not pass', function () {
    $files = new InMemoryProjectFiles(SampleProject::files());
    $llm = new FakeLlm([
        ['summary' => 'Notes', 'files' => [
            ['path' => 'app/Http/Requests/StoreNoteRequest.php', 'type' => 'request', 'action' => 'CREATE', 'description' => 'x'],
            ['path' => 'tests/Feature/NoteTest.php', 'type' => 'test', 'action' => 'CREATE', 'description' => 'x'],
        ]],
        ['files' => [
            ['path' => 'app/Http/Requests/StoreNoteRequest.php', 'content' => NOTE_REQUEST],
            ['path' => 'tests/Feature/NoteTest.php', 'content' => "<?php\n\nit('x', function () {\n    expect(1)->toBe(1);\n});\n"],
        ]],
        // two healing attempts that do not help
        ['files' => [['path' => 'tests/Feature/NoteTest.php', 'content' => "<?php\n\nit('x', function () {\n    expect(2)->toBe(2);\n});\n"]]],
        ['files' => [['path' => 'tests/Feature/NoteTest.php', 'content' => "<?php\n\nit('x', function () {\n    expect(3)->toBe(3);\n});\n"]]],
    ]);
    $tests = new FakeTestRunner([TestResult::fail('still red')]);
    $progress = new ConsoleProgress(fn () => null, false, fn () => 0.0);

    $context = qualityPipeline($llm, $tests, $files, $progress, $this->dir)->run(new MorphContext('notes', assumeYes: true, runTrust: true));

    expect($context->trust)->toBeNull();
    expect($context->hasFailed())->toBeTrue();
});

it('lets the developer opt out of conventions and trust', function () {
    $files = new InMemoryProjectFiles(SampleProject::files());
    $llm = new FakeLlm([
        ['summary' => 'Notes', 'files' => [['path' => 'app/Http/Requests/StoreNoteRequest.php', 'type' => 'request', 'action' => 'CREATE', 'description' => 'x']]],
        ['files' => [['path' => 'app/Http/Requests/StoreNoteRequest.php', 'content' => NOTE_REQUEST]]],
    ]);
    $progress = new ConsoleProgress(fn () => null, false, fn () => 0.0);

    $context = qualityPipeline($llm, new FakeTestRunner([TestResult::pass()]), $files, $progress, $this->dir)
        ->run(new MorphContext('notes', assumeYes: true, useConventions: false, runTrust: false));

    expect($context->conventions)->toBeNull();
    expect($llm->userPrompts[0])->not->toContain('## Project conventions');
    expect($context->trust)->toBeNull();
});

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/chimera_quality_'.bin2hex(random_bytes(4));
});
