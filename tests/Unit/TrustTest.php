<?php

use Abdelhmed\Chimera\Data\FileSpec;
use Abdelhmed\Chimera\Data\GeneratedFile;
use Abdelhmed\Chimera\Data\TestResult;
use Abdelhmed\Chimera\Enums\FileAction;
use Abdelhmed\Chimera\Enums\FileType;
use Abdelhmed\Chimera\Injection\FormatPreservingEditor;
use Abdelhmed\Chimera\Pipeline\MorphContext;
use Abdelhmed\Chimera\Pipeline\Stages\RecoverInterruptedMutationsStage;
use Abdelhmed\Chimera\Support\NullProgress;
use Abdelhmed\Chimera\Support\NullReporter;
use Abdelhmed\Chimera\Tests\Support\FakeTestRunner;
use Abdelhmed\Chimera\Tests\Support\InMemoryProjectFiles;
use Abdelhmed\Chimera\Trust\FilesystemMutationRecovery;
use Abdelhmed\Chimera\Trust\MutantGenerator;
use Abdelhmed\Chimera\Trust\MutationRunner;
use Abdelhmed\Chimera\Trust\Operators;
use Abdelhmed\Chimera\Trust\TrustAnalyzer;

const TRUST_REQUEST = <<<'PHP'
<?php

namespace App\Http\Requests;

class StoreTaskRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'tags' => ['required', 'array'],
            'note' => 'nullable|string',
        ];
    }
}
PHP;

const TRUST_CONTROLLER = <<<'PHP'
<?php

namespace App\Http\Controllers;

class TaskController
{
    public function update($request, $task)
    {
        $this->authorize('update', $task);

        if (! $task->isOpen() && $request->user()->id !== $task->user_id || $task->locked) {
            abort(403);
        }

        if ($task->priority >= 3) {
            $task->touch();
        }

        return response()->json($task, 201);
    }
}
PHP;

function mutants(): MutantGenerator
{
    return new MutantGenerator([
        new Operators\ComparisonOperator(), new Operators\LogicalOperator(), new Operators\NegationOperator(),
        new Operators\StatusCodeOperator(), new Operators\RequiredRuleOperator(), new Operators\AuthorizationOperator(),
    ], new FormatPreservingEditor());
}

it('plants exactly one valid bug per mutant, in every supported place', function () {
    $found = [];
    foreach (mutants()->generate('app/Http/Controllers/TaskController.php', TRUST_CONTROLLER, 50) as $m) {
        $found[$m->operator][] = $m->description;
        expect($m->code === TRUST_CONTROLLER)->toBeFalse();
        expect(str_contains($m->code, '<?php'))->toBeTrue();
    }

    expect($found['guard'])->toBe(['removed the guard `authorize()`']);
    expect($found['negation'])->toBe(['removed a `!` negation']);
    expect($found['comparison'])->toContain('changed `!==` to `===`');
    expect($found['comparison'])->toContain('changed `>=` to `<`');
    expect($found['logical'])->toHaveCount(2);
    expect($found['status-code'])->toBe(['changed status code 403 to 404 in abort()', 'changed status code 201 to 200 in json()']);

    $rules = mutants()->generate('app/Http/Requests/StoreTaskRequest.php', TRUST_REQUEST, 50);
    expect(array_map(fn ($m) => $m->description, $rules))->toBe(["removed the `required` rule from 'title'", "removed the `required` rule from 'tags'"]);
    expect($rules[0]->code)->toContain("'title' => 'string|max:255'");
    expect($rules[1]->code)->toContain("'tags' => ['array']");
    expect($rules[1]->code)->toContain("'title' => 'required|string|max:255'"); // only one change per mutant
});

it('spreads a limited number of mutants over the whole file', function () {
    $mutants = mutants()->generate('x.php', TRUST_CONTROLLER, 3);

    expect($mutants)->toHaveCount(3);
    expect($mutants[0]->line < $mutants[2]->line)->toBeTrue();
});

function trustSetup(array $testResults): array
{
    $files = new InMemoryProjectFiles([
        'app/Http/Requests/StoreTaskRequest.php' => TRUST_REQUEST,
        'app/Http/Controllers/TaskController.php' => TRUST_CONTROLLER,
        'tests/Feature/TaskTest.php' => "<?php\nit('works', fn () => null);\n",
    ]);
    $recovery = new FilesystemMutationRecovery(sys_get_temp_dir().'/chimera_rec_'.bin2hex(random_bytes(4)).'.json');
    $tests = new FakeTestRunner($testResults);
    $analyzer = new TrustAnalyzer($files, mutants(), new MutationRunner($files, $tests, $recovery), 4);

    $generated = [
        new GeneratedFile(new FileSpec('app/Http/Requests/StoreTaskRequest.php', FileType::Request, FileAction::Create), TRUST_REQUEST),
        new GeneratedFile(new FileSpec('app/Http/Controllers/TaskController.php', FileType::Controller, FileAction::Create), TRUST_CONTROLLER),
        new GeneratedFile(new FileSpec('app/Models/Task.php', FileType::Model, FileAction::Inject), '<?php class Task {}'),
        new GeneratedFile(new FileSpec('tests/Feature/TaskTest.php', FileType::Test, FileAction::Create), '<?php'),
    ];

    return [$analyzer, $generated, $files, $recovery, $tests];
}

it('scores the tests by the share of deliberate bugs they catch and lists the ones that went unnoticed', function () {
    // Mutants are taken from each file in turn: request(title), controller, request(tags), controller.
    // So the third one, removing `required` from 'tags', is the one that goes unnoticed here.
    [$analyzer, $generated, $files, $recovery, $tests] = trustSetup([TestResult::fail('x'), TestResult::fail('x'), TestResult::pass(), TestResult::fail('x')]);

    $report = $analyzer->analyze($generated, new NullProgress());

    expect($report->total())->toBe(4);
    expect($report->killed())->toBe(3);
    expect($report->score())->toBe(75);
    expect($report->survivors())->toHaveCount(1);
    expect($report->survivors()[0]->label())->toContain("StoreTaskRequest.php:");
    expect($report->survivors()[0]->label())->toContain("removed the `required` rule from 'tags'");
    expect($tests->runs)->toBe(4);

    // the project is exactly as it was, and nothing is left to recover
    expect($files->files['app/Http/Controllers/TaskController.php'])->toBe(TRUST_CONTROLLER);
    expect($files->files['app/Http/Requests/StoreTaskRequest.php'])->toBe(TRUST_REQUEST);
    expect($recovery->pending())->toBe([]);
});

it('skips when there is nothing to measure', function () {
    [$analyzer, $generated] = trustSetup([TestResult::pass()]);

    $noTests = array_values(array_filter($generated, fn ($f) => $f->type() !== FileType::Test));
    expect($analyzer->analyze($noTests, new NullProgress())->wasSkipped())->toBeTrue();

    $onlyTests = array_values(array_filter($generated, fn ($f) => $f->type() === FileType::Test));
    expect($analyzer->analyze($onlyTests, new NullProgress())->skippedReason)->toContain('no logic');

    [$analyzer2, $generated2] = trustSetup([TestResult::skip('no pest')]);
    expect($analyzer2->analyze($generated2, new NullProgress())->skippedReason)->toContain('could not be run');
});

it('puts the original file back even when the test run blows up, and a later run repairs a killed process', function () {
    $files = new InMemoryProjectFiles(['app/Http/Controllers/TaskController.php' => TRUST_CONTROLLER]);
    $recovery = new FilesystemMutationRecovery(sys_get_temp_dir().'/chimera_rec_'.bin2hex(random_bytes(4)).'.json');
    $exploding = new class implements \Abdelhmed\Chimera\Contracts\TestRunner {
        public function run(array $testPaths, ?int $timeout = null, bool $stopOnFailure = false): TestResult
        {
            throw new RuntimeException('boom');
        }
    };

    $mutation = mutants()->generate('app/Http/Controllers/TaskController.php', TRUST_CONTROLLER, 1)[0];
    expect(fn () => (new MutationRunner($files, $exploding, $recovery))->run($mutation, ['tests/x.php']))->toThrow(RuntimeException::class);
    expect($files->files['app/Http/Controllers/TaskController.php'])->toBe(TRUST_CONTROLLER);
    expect($recovery->pending())->toBe([]);

    // Simulate SIGKILL: the file holds a bug and the recovery file still has the original.
    $files->put('app/Http/Controllers/TaskController.php', $mutation->code);
    $recovery->remember('app/Http/Controllers/TaskController.php', TRUST_CONTROLLER);

    $reporter = new NullReporter();
    (new RecoverInterruptedMutationsStage($recovery, $files, $reporter))->handle(new MorphContext('x'));

    expect($files->files['app/Http/Controllers/TaskController.php'])->toBe(TRUST_CONTROLLER);
    expect($recovery->pending())->toBe([]);
    expect($reporter->messages[0])->toContain('interrupted');
});
