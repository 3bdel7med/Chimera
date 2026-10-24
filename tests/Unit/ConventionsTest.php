<?php

use Abdelhmed\Chimera\Conventions\Checks;
use Abdelhmed\Chimera\Conventions\Convention;
use Abdelhmed\Chimera\Conventions\ConventionChecker;
use Abdelhmed\Chimera\Conventions\ConventionLearner;
use Abdelhmed\Chimera\Conventions\ConventionPrompt;
use Abdelhmed\Chimera\Conventions\ConventionSet;
use Abdelhmed\Chimera\Conventions\Detectors;
use Abdelhmed\Chimera\Conventions\FilesystemConventionStore;
use Abdelhmed\Chimera\Data\FileSpec;
use Abdelhmed\Chimera\Data\GeneratedFile;
use Abdelhmed\Chimera\Enums\FileAction;
use Abdelhmed\Chimera\Enums\FileType;
use Abdelhmed\Chimera\Tests\Support\InMemoryProjectFiles;
use Abdelhmed\Chimera\Tests\Support\SampleProject;

function learner(array $files): ConventionLearner
{
    return new ConventionLearner(new InMemoryProjectFiles($files), [
        new Detectors\ValidationDetector(), new Detectors\ResponseDetector(), new Detectors\ArchitectureDetector(), new Detectors\ModelDetector(),
        new Detectors\PhpStyleDetector(), new Detectors\TestingDetector(), new Detectors\RoutingDetector(), new Detectors\MigrationDetector(),
    ]);
}

function checker(array $files = []): ConventionChecker
{
    return new ConventionChecker([
        new Checks\FormRequestCheck(), new Checks\NoQueriesInControllersCheck(), new Checks\StrictTypesCheck(),
        new Checks\ReturnTypesCheck(), new Checks\PestStyleCheck(), new Checks\PhpUnitStyleCheck(),
    ], new InMemoryProjectFiles($files));
}

function generatedFile(string $path, FileType $type, string $code): GeneratedFile
{
    return new GeneratedFile(new FileSpec($path, $type, FileAction::Create), $code);
}

it('learns the habits of a project from its AST', function () {
    $set = learner(SampleProject::files())->learn();
    $ids = array_map(fn (Convention $c) => $c->id, $set->conventions);

    foreach (['validation.form-request', 'responses.resources', 'architecture.services', 'architecture.no-queries-in-controllers', 'models.fillable',
        'models.has-factory', 'models.casts-method', 'php.strict-types', 'php.return-types', 'tests.pest', 'tests.refresh-database-global',
        'routing.resource-routes', 'routing.auth-sanctum', 'migrations.anonymous', 'migrations.constrained', 'migrations.down'] as $expected) {
        expect($ids)->toContain($expected);
    }

    expect($set->filesAnalysed)->toBe(count(SampleProject::files()));
});

it('does not invent conventions from too little or mixed evidence', function () {
    expect(learner([])->learn()->isEmpty())->toBeTrue();

    $mixed = SampleProject::files();
    // half of the controllers validate inline: no clear habit, so nothing is learned about validation
    foreach (['Post', 'Comment'] as $m) {
        $mixed["app/Http/Controllers/{$m}Controller.php"] = "<?php\n\nclass {$m}Controller\n{\n    public function store(\$request)\n    {\n        \$request->validate(['a' => 'required']);\n    }\n\n    public function update(\$request)\n    {\n        \$request->validate(['a' => 'required']);\n    }\n}\n";
    }
    $ids = array_map(fn (Convention $c) => $c->id, learner($mixed)->learn()->conventions);

    expect($ids)->not->toContain('validation.form-request');
    expect($ids)->not->toContain('validation.inline');
});

it('keeps what the developer curated when conventions are learned again', function () {
    $first = learner(SampleProject::files())->learn();
    $curated = new ConventionSet([
        ...array_map(fn (Convention $c) => $c->id === 'php.strict-types' ? $c->withEnabled(false) : $c, $first->conventions),
        new Convention('custom.money', 'Store money as integer cents.', 1.0, 'team decision', true, 'custom'),
    ], $first->filesAnalysed);

    $again = learner(SampleProject::files())->learn($curated);
    $byId = [];
    foreach ($again->conventions as $c) {
        $byId[$c->id] = $c;
    }

    expect($byId['php.strict-types']->enabled)->toBeFalse();
    expect($byId['custom.money']->rule)->toBe('Store money as integer cents.');
    expect($byId['models.fillable']->enabled)->toBeTrue();
    expect($again->rules())->toContain('Store money as integer cents.');
    expect($again->rules())->not->toContain('Start every PHP file under app/ with declare(strict_types=1);');
});

it('saves to a JSON file that round-trips and is stable for version control', function () {
    $file = sys_get_temp_dir().'/chimera_conv_'.bin2hex(random_bytes(4)).'/.chimera/conventions.json';
    $store = new FilesystemConventionStore($file);

    expect($store->load())->toBeNull();

    $set = learner(SampleProject::files())->learn();
    $store->save($set);
    $loaded = $store->load();

    expect(count($loaded->conventions))->toBe(count($set->conventions));
    expect($loaded->rules())->toBe($set->rules());

    $json = file_get_contents($file);
    $store->save($loaded);
    expect(file_get_contents($file))->toBe($json);
});

it('shows conventions to the AI as their own section, not as JSON noise', function () {
    [$rules, $context] = ConventionPrompt::split(['models' => [], 'conventions' => ['Use Pest.', 'Use services.']]);

    expect($rules)->toBe(['Use Pest.', 'Use services.']);
    expect($context)->toBe(['models' => []]);
    expect(ConventionPrompt::section($rules))->toContain("- Use Pest.\n- Use services.");
    expect(ConventionPrompt::section([]))->toBeNull();
});

it('catches code that breaks an enforceable convention, and only those the project has', function () {
    $set = learner(SampleProject::files())->learn();
    $controller = generatedFile('app/Http/Controllers/NoteController.php', FileType::Controller,
        "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass NoteController\n{\n    public function store(\$request)\n    {\n        \$request->validate(['title' => 'required']);\n\n        return Note::where('a', 1)->get();\n    }\n}\n");
    $test = generatedFile('tests/Feature/NoteTest.php', FileType::Test, "<?php\n\nclass NoteTest extends TestCase\n{\n}\n");

    $violations = checker(['app/Models/Note.php' => '<?php'])->check([$controller, $test], $set);
    $byConvention = [];
    foreach ($violations as $v) {
        $byConvention[$v->conventionId][] = $v->message;
    }

    expect(array_keys($byConvention))->toBe(['validation.form-request', 'architecture.no-queries-in-controllers', 'php.strict-types', 'php.return-types', 'tests.pest']);
    expect($byConvention['validation.form-request'][0])->toContain('NoteController::store() validates inline');
    expect($byConvention['architecture.no-queries-in-controllers'][0])->toContain('Note::where()');
    expect($byConvention['php.return-types'][0])->toContain('NoteController::store() has no return type');

    // a convention the developer switched off is not enforced
    $relaxed = new ConventionSet(array_map(fn (Convention $c) => $c->id === 'php.strict-types' ? $c->withEnabled(false) : $c, $set->conventions));
    $ids = array_unique(array_map(fn ($v) => $v->conventionId, checker(['app/Models/Note.php' => '<?php'])->check([$controller], $relaxed)));
    expect($ids)->not->toContain('php.strict-types');
});

it('accepts code that follows the conventions and ignores INJECT fragments', function () {
    $set = learner(SampleProject::files())->learn();
    $clean = generatedFile('app/Http/Controllers/NoteController.php', FileType::Controller,
        "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Http\\Controllers;\n\nclass NoteController\n{\n    public function store(StoreNoteRequest \$request): NoteResource\n    {\n        return new NoteResource(\$this->service->create(\$request->validated()));\n    }\n}\n");
    $fragment = new GeneratedFile(new FileSpec('app/Models/User.php', FileType::Model, FileAction::Inject), "<?php\nclass User { public function notes() { return \$this->hasMany(Note::class); } }");

    expect(checker()->check([$clean, $fragment], $set))->toBe([]);
    expect(checker()->canEnforce(new ConventionSet([])))->toBeFalse();
});
