<?php

use Abdelhmed\Chimera\Analysis\AstProjectScanner;
use Abdelhmed\Chimera\Analysis\ClassSummarizer;
use Abdelhmed\Chimera\Analysis\RouteSummarizer;
use Abdelhmed\Chimera\Application\ChangeApplier;
use Abdelhmed\Chimera\Checkpoint\FilesystemCheckpointStore;
use Abdelhmed\Chimera\Checkpoint\RollbackService;
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
use Abdelhmed\Chimera\Pipeline\Stages\ApplyChangesStage;
use Abdelhmed\Chimera\Pipeline\Stages\ConfirmBlueprintStage;
use Abdelhmed\Chimera\Pipeline\Stages\GenerateFilesStage;
use Abdelhmed\Chimera\Pipeline\Stages\MigrateStage;
use Abdelhmed\Chimera\Pipeline\Stages\PlanBlueprintStage;
use Abdelhmed\Chimera\Pipeline\Stages\ScanProjectStage;
use Abdelhmed\Chimera\Pipeline\Stages\SummaryStage;
use Abdelhmed\Chimera\Pipeline\Stages\VerifyAndHealStage;
use Abdelhmed\Chimera\Planning\BatchPlanner;
use Abdelhmed\Chimera\Planning\BlueprintNormalizer;
use Abdelhmed\Chimera\Planning\BlueprintParser;
use Abdelhmed\Chimera\Planning\BlueprintPlanner;
use Abdelhmed\Chimera\Console\ConsoleProgress;
use Abdelhmed\Chimera\Contracts\ProgressTracker;
use Abdelhmed\Chimera\Support\NullProgress;
use Abdelhmed\Chimera\Support\NullReporter;
use Abdelhmed\Chimera\Support\PathGuard;
use Abdelhmed\Chimera\Support\PromptRepository;
use Abdelhmed\Chimera\Tests\Support\FakeLlm;
use Abdelhmed\Chimera\Tests\Support\FakeMigrationRunner;
use Abdelhmed\Chimera\Tests\Support\FakeTestRunner;
use Abdelhmed\Chimera\Tests\Support\InMemoryProjectFiles;
use Abdelhmed\Chimera\Verification\HealingService;

const E2E_USER = "<?php\n\nnamespace App\\Models;\n\nclass User\n{\n    protected \$fillable = ['name'];\n}\n";
const E2E_ROUTES = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/ping', fn () => 'pong');\n";

/** Builds the whole pipeline from real classes, with only the AI, test runner, migrations and console faked. */
function e2e(FakeLlm $llm, FakeTestRunner $tests, InMemoryProjectFiles $files, FakeMigrationRunner $migrations, NullReporter $reporter, string $dir, ?ProgressTracker $progress = null): array
{
    $progress ??= new NullProgress();
    $editor = new FormatPreservingEditor();
    $imports = new ImportMerger();
    $snippets = new SnippetParser();
    $registry = new InjectorRegistry(new ClassMembersInjector($editor, $imports, $snippets), new RouteInjector($editor, $imports, $snippets));
    $guard = new PathGuard(['app', 'routes', 'database', 'tests']);
    $summarizer = new ClassSummarizer();
    $prompts = new PromptRepository(__DIR__.'/../../resources/prompts');
    $store = new FilesystemCheckpointStore($dir);

    $generator = new FileGenerator($llm, $prompts, new BatchPromptBuilder($files, $summarizer), new GeneratedFileValidator($files, $registry), $files, 1);
    $applier = new ChangeApplier($files, $guard, $registry, $store);
    $rollback = new RollbackService($files, $store, $migrations);
    $normalizer = new BlueprintNormalizer($guard, $files, 40, fn () => new DateTimeImmutable('2026-10-23 10:00:00'));

    $pipeline = new MorphPipeline([
        new ScanProjectStage(new AstProjectScanner($files, $summarizer, new RouteSummarizer()), $progress),
        new PlanBlueprintStage(new BlueprintPlanner($llm, $prompts, new BlueprintParser(), $normalizer), $reporter, $progress),
        new ConfirmBlueprintStage($reporter, $progress),
        new GenerateFilesStage($generator, new BatchPlanner(2), $reporter, $progress),
        new ApplyChangesStage($applier, $store, $rollback, $reporter, $progress),
        new MigrateStage($migrations, $store, $reporter, $progress),
        new VerifyAndHealStage(new HealingService($tests, $generator, $applier, 2), $reporter, $progress),
        new SummaryStage($reporter, $progress),
    ]);

    return [$pipeline, $store, $rollback];
}

function blueprintReply(): array
{
    return [
        'summary' => 'Posts for users',
        'composerPackages' => ['spatie/laravel-data'],
        'files' => [
            ['path' => 'database/migrations/create_posts_table.php', 'type' => 'migration', 'action' => 'CREATE', 'description' => 'posts: id, user_id, title'],
            ['path' => 'app/Models/Post.php', 'type' => 'model', 'action' => 'CREATE', 'description' => 'Post model'],
            ['path' => 'app/Models/User.php', 'type' => 'model', 'action' => 'INJECT', 'description' => 'posts() relation'],
            ['path' => 'routes/api.php', 'type' => 'route', 'action' => 'INJECT', 'description' => 'posts route'],
            ['path' => 'tests/Feature/PostTest.php', 'type' => 'test', 'action' => 'CREATE', 'description' => 'post test'],
        ],
    ];
}

function codeReply(string $migrationPath): array
{
    $batch1 = ['files' => [
        ['path' => $migrationPath, 'content' => "<?php\nreturn new class {};\n"],
        ['path' => 'app/Models/Post.php', 'content' => "<?php\n\nnamespace App\\Models;\n\nclass Post\n{\n    protected \$fillable = ['title'];\n}\n"],
    ]];
    $batch2 = ['files' => [
        ['path' => 'app/Models/User.php', 'content' => "<?php\nuse App\\Models\\Post;\nclass User\n{\n    public function posts()\n    {\n        return \$this->hasMany(Post::class);\n    }\n}\n"],
        ['path' => 'routes/api.php', 'content' => "Route::get('/posts', fn () => []);"],
    ]];
    $batch3 = ['files' => [
        ['path' => 'tests/Feature/PostTest.php', 'content' => "<?php\n// v1\n"],
    ]];

    return [$batch1, $batch2, $batch3];
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/chimera_test_'.bin2hex(random_bytes(4));
});

it('runs the whole flow, heals a failing test, and can be rolled back completely', function () {
    $files = new InMemoryProjectFiles(['app/Models/User.php' => E2E_USER, 'routes/api.php' => E2E_ROUTES]);
    $original = $files->files;
    $migrationPath = 'database/migrations/2026_10_23_100000_create_posts_table.php';

    $llm = new FakeLlm([
        blueprintReply(),
        ...codeReply($migrationPath),
        ['files' => [['path' => 'tests/Feature/PostTest.php', 'content' => "<?php\n// v2 healed\n"]]],
    ]);
    $tests = new FakeTestRunner([TestResult::fail('1 failed'), TestResult::pass('ok')]);
    $migrations = new FakeMigrationRunner();
    $reporter = new NullReporter();

    $screen = '';
    $progress = new ConsoleProgress(function (string $text) use (&$screen) { $screen .= $text; }, false, fn () => 0.0);

    [$pipeline, $store, $rollback] = e2e($llm, $tests, $files, $migrations, $reporter, $this->dir, $progress);
    $context = $pipeline->run(new MorphContext('posts feature', assumeYes: true));

    expect($context->hasFailed())->toBeFalse();
    expect($files->files)->toHaveKey($migrationPath);
    expect($files->files['app/Models/User.php'])->toContain('function posts()');
    expect($files->files['routes/api.php'])->toContain("Route::get('/posts'");
    expect($files->files['routes/api.php'])->toContain("Route::get('/ping'");
    expect($files->files['tests/Feature/PostTest.php'])->toContain('v2 healed');
    expect($context->healedPaths)->toBe(['tests/Feature/PostTest.php']);
    expect($migrations->migrated)->toBe([[$migrationPath]]);
    expect($tests->runs)->toBe(2);

    // The developer sees the story in order, ending with the file tree.
    $order = ['▸ Scanning your project', '✔ Scanning your project', '✔ Planning with the AI', '▸ Reviewing the plan', '✔ Generating code',
        '✔ Applying changes', '✔ Running migrations', '✔ Verifying with tests', '└─'];
    $last = -1;
    foreach ($order as $marker) {
        $position = strpos($screen, $marker, $last + 1);
        expect($position === false)->toBeFalse();
        $last = $position;
    }
    expect($screen)->toContain('injected (AST)');
    expect($screen)->toContain('fixed by the AI');
    expect($screen)->toContain('2 files analysed with the AST');

    $checkpoint = $store->latest();
    expect($checkpoint->created)->toContain('app/Models/Post.php');
    expect($checkpoint->modified)->toBe(['app/Models/User.php', 'routes/api.php']);

    $rollback->rollback($checkpoint->id);

    expect($files->files)->toBe($original);
    expect($migrations->rolledBack)->toBe([[$migrationPath]]);
    expect($store->latest())->toBeNull();
});

it('stops after the blueprint on a dry run and writes nothing', function () {
    $files = new InMemoryProjectFiles(['app/Models/User.php' => E2E_USER]);
    $llm = new FakeLlm([blueprintReply()]);
    [$pipeline] = e2e($llm, new FakeTestRunner([TestResult::pass()]), $files, new FakeMigrationRunner(), new NullReporter(), $this->dir);

    $context = $pipeline->run(new MorphContext('posts', dryRun: true));

    expect($context->isHalted())->toBeTrue();
    expect($context->hasFailed())->toBeFalse();
    expect($files->files)->toHaveCount(1);
    expect(count($llm->userPrompts))->toBe(1);
});

it('does nothing when the developer declines', function () {
    $files = new InMemoryProjectFiles(['app/Models/User.php' => E2E_USER]);
    $llm = new FakeLlm([blueprintReply()]);
    [$pipeline] = e2e($llm, new FakeTestRunner([TestResult::pass()]), $files, new FakeMigrationRunner(), new NullReporter(confirmAnswer: false), $this->dir);

    $context = $pipeline->run(new MorphContext('posts'));

    expect($context->isHalted())->toBeTrue();
    expect($files->files)->toHaveCount(1);
});

it('restores the project when writing fails half-way through applying', function () {
    $original = ['app/Models/User.php' => E2E_USER, 'routes/api.php' => E2E_ROUTES];
    $migrationPath = 'database/migrations/2026_10_23_100000_create_posts_table.php';

    // The last file to be applied (routes/api.php) fails to write, after earlier files were already written.
    $files = new class($original) extends InMemoryProjectFiles {
        private bool $failed = false;

        public function put(string $path, string $content): void
        {
            if ($path === 'routes/api.php' && ! $this->failed) {
                $this->failed = true;
                throw new RuntimeException('disk full');
            }
            parent::put($path, $content);
        }
    };

    $llm = new FakeLlm([blueprintReply(), ...codeReply($migrationPath)]);
    [$pipeline, $store] = e2e($llm, new FakeTestRunner([TestResult::pass()]), $files, new FakeMigrationRunner(), new NullReporter(), $this->dir);

    expect(fn () => $pipeline->run(new MorphContext('posts', assumeYes: true)))->toThrow(RuntimeException::class);
    expect($files->files)->toBe($original);
    expect($store->latest())->toBeNull(); // the checkpoint was rolled back automatically
});
