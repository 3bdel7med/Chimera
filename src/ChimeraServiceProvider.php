<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera;

use Abdelhmed\Chimera\Analysis\AstProjectScanner;
use Abdelhmed\Chimera\Analysis\ClassSummarizer;
use Abdelhmed\Chimera\Analysis\RouteSummarizer;
use Abdelhmed\Chimera\Application\ChangeApplier;
use Abdelhmed\Chimera\Checkpoint\FilesystemCheckpointStore;
use Abdelhmed\Chimera\Console\ConsoleReporter;
use Abdelhmed\Chimera\Console\ConsoleProgress;
use Abdelhmed\Chimera\Console\HistoryCommand;
use Abdelhmed\Chimera\Console\LearnCommand;
use Abdelhmed\Chimera\Console\MorphCommand;
use Abdelhmed\Chimera\Console\RollbackCommand;
use Abdelhmed\Chimera\Contracts\CheckpointStore;
use Abdelhmed\Chimera\Contracts\ConventionStore;
use Abdelhmed\Chimera\Contracts\LlmClient;
use Abdelhmed\Chimera\Contracts\MigrationRunner;
use Abdelhmed\Chimera\Contracts\MutationRecovery;
use Abdelhmed\Chimera\Contracts\ProgressTracker;
use Abdelhmed\Chimera\Contracts\ProjectFiles;
use Abdelhmed\Chimera\Contracts\ProjectScanner;
use Abdelhmed\Chimera\Contracts\Reporter;
use Abdelhmed\Chimera\Contracts\TestRunner;
use Abdelhmed\Chimera\Conventions\Checks;
use Abdelhmed\Chimera\Conventions\ConventionChecker;
use Abdelhmed\Chimera\Conventions\ConventionLearner;
use Abdelhmed\Chimera\Conventions\Detectors;
use Abdelhmed\Chimera\Conventions\FilesystemConventionStore;
use Abdelhmed\Chimera\Exceptions\ConfigurationException;
use Abdelhmed\Chimera\Filesystem\LocalProjectFiles;
use Abdelhmed\Chimera\Generation\BatchPromptBuilder;
use Abdelhmed\Chimera\Generation\FileGenerator;
use Abdelhmed\Chimera\Generation\GeneratedFileValidator;
use Abdelhmed\Chimera\Llm\GeminiClient;
use Abdelhmed\Chimera\Llm\GeminiSettings;
use Abdelhmed\Chimera\Migration\ArtisanMigrationRunner;
use Abdelhmed\Chimera\Pipeline\MorphPipeline;
use Abdelhmed\Chimera\Pipeline\Stages\ApplyChangesStage;
use Abdelhmed\Chimera\Pipeline\Stages\ConfirmBlueprintStage;
use Abdelhmed\Chimera\Pipeline\Stages\ConventionAuditStage;
use Abdelhmed\Chimera\Pipeline\Stages\GenerateFilesStage;
use Abdelhmed\Chimera\Pipeline\Stages\LoadConventionsStage;
use Abdelhmed\Chimera\Pipeline\Stages\MigrateStage;
use Abdelhmed\Chimera\Pipeline\Stages\PlanBlueprintStage;
use Abdelhmed\Chimera\Pipeline\Stages\RecoverInterruptedMutationsStage;
use Abdelhmed\Chimera\Pipeline\Stages\ScanProjectStage;
use Abdelhmed\Chimera\Pipeline\Stages\SummaryStage;
use Abdelhmed\Chimera\Pipeline\Stages\TrustScoreStage;
use Abdelhmed\Chimera\Pipeline\Stages\VerifyAndHealStage;
use Abdelhmed\Chimera\Planning\BatchPlanner;
use Abdelhmed\Chimera\Planning\BlueprintParser;
use Abdelhmed\Chimera\Planning\BlueprintNormalizer;
use Abdelhmed\Chimera\Planning\BlueprintPlanner;
use Abdelhmed\Chimera\Support\PathGuard;
use Abdelhmed\Chimera\Support\PromptRepository;
use Abdelhmed\Chimera\Trust\FilesystemMutationRecovery;
use Abdelhmed\Chimera\Trust\MutantGenerator;
use Abdelhmed\Chimera\Trust\MutationRunner;
use Abdelhmed\Chimera\Trust\Operators;
use Abdelhmed\Chimera\Trust\TrustAnalyzer;
use Abdelhmed\Chimera\Injection\FormatPreservingEditor;
use Abdelhmed\Chimera\Verification\HealingService;
use Abdelhmed\Chimera\Verification\ProcessTestRunner;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/** Wires the interfaces to their implementations. Rebind any contract here to swap a part (e.g. another LLM). */
class ChimeraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chimera.php', 'chimera');

        $this->registerInfrastructure();
        $this->registerPlanningAndGeneration();
        $this->registerConventionsAndTrust();
        $this->registerPipeline();
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([MorphCommand::class, LearnCommand::class, RollbackCommand::class, HistoryCommand::class]);

        $this->publishes([__DIR__.'/../config/chimera.php' => config_path('chimera.php')], 'chimera-config');
        $this->publishes([__DIR__.'/../resources/prompts' => base_path('stubs/chimera')], 'chimera-prompts');
    }

    private function registerInfrastructure(): void
    {
        $this->app->singleton(PathGuard::class, fn () => new PathGuard(
            (array) config('chimera.safety.writable_paths'),
            (array) config('chimera.safety.protected_segments'),
        ));

        $this->app->singleton(ProjectFiles::class, fn (Application $app) => new LocalProjectFiles($app->basePath()));

        $this->app->singleton(PromptRepository::class, fn () => new PromptRepository(
            __DIR__.'/../resources/prompts',
            config('chimera.prompts.path'),
        ));

        $this->app->singleton(LlmClient::class, function (Application $app): LlmClient {
            $driver = (string) config('chimera.driver', 'gemini');

            return match ($driver) {
                'gemini' => new GeminiClient($app->make(HttpFactory::class), GeminiSettings::fromArray((array) config('chimera.gemini'))),
                default => throw new ConfigurationException("Unknown Chimera driver [{$driver}]. Bind your own LlmClient to use another model."),
            };
        });

        $this->app->singleton(ProjectScanner::class, fn (Application $app) => new AstProjectScanner(
            $app->make(ProjectFiles::class),
            $app->make(ClassSummarizer::class),
            $app->make(RouteSummarizer::class),
            (array) config('chimera.context'),
        ));

        $this->app->singleton(CheckpointStore::class, fn () => new FilesystemCheckpointStore((string) config('chimera.checkpoints.path')));
        $this->app->singleton(MigrationRunner::class, ArtisanMigrationRunner::class);
        $this->app->singleton(Reporter::class, ConsoleReporter::class);

        $this->app->singleton(ProgressTracker::class, fn () => new ConsoleProgress(
            fn (string $text) => fwrite(STDOUT, $text),
            ansi: stream_isatty(STDOUT) && getenv('NO_COLOR') === false,
        ));

        $this->app->singleton(TestRunner::class, fn (Application $app) => new ProcessTestRunner(
            $app->basePath(),
            (array) config('chimera.testing.env'),
            (int) config('chimera.testing.timeout', 300),
        ));
    }

    private function registerPlanningAndGeneration(): void
    {
        $this->app->singleton(BlueprintNormalizer::class, fn (Application $app) => new BlueprintNormalizer(
            $app->make(PathGuard::class),
            $app->make(ProjectFiles::class),
            (int) config('chimera.generation.max_files', 40),
        ));

        $this->app->singleton(BlueprintPlanner::class, fn (Application $app) => new BlueprintPlanner(
            $app->make(LlmClient::class),
            $app->make(PromptRepository::class),
            $app->make(BlueprintParser::class),
            $app->make(BlueprintNormalizer::class),
            (int) config('chimera.generation.max_files', 40),
        ));

        $this->app->singleton(BatchPlanner::class, fn () => new BatchPlanner((int) config('chimera.generation.files_per_batch', 4)));

        $this->app->singleton(FileGenerator::class, fn (Application $app) => new FileGenerator(
            $app->make(LlmClient::class),
            $app->make(PromptRepository::class),
            $app->make(BatchPromptBuilder::class),
            $app->make(GeneratedFileValidator::class),
            $app->make(ProjectFiles::class),
            (int) config('chimera.generation.max_syntax_retries', 2),
        ));

        $this->app->singleton(HealingService::class, fn (Application $app) => new HealingService(
            $app->make(TestRunner::class),
            $app->make(FileGenerator::class),
            $app->make(ChangeApplier::class),
            (int) config('chimera.testing.max_heal_attempts', 2),
        ));
    }

    private function registerConventionsAndTrust(): void
    {
        $this->app->singleton(ConventionStore::class, fn () => new FilesystemConventionStore((string) config('chimera.conventions.path')));

        $this->app->singleton(ConventionLearner::class, fn (Application $app) => new ConventionLearner(
            $app->make(ProjectFiles::class),
            [
                new Detectors\ValidationDetector(),
                new Detectors\ResponseDetector(),
                new Detectors\ArchitectureDetector(),
                new Detectors\ModelDetector(),
                new Detectors\PhpStyleDetector(),
                new Detectors\TestingDetector(),
                new Detectors\RoutingDetector(),
                new Detectors\MigrationDetector(),
            ],
            (int) config('chimera.conventions.max_files', 400),
        ));

        $this->app->singleton(ConventionChecker::class, fn (Application $app) => new ConventionChecker(
            [
                new Checks\FormRequestCheck(),
                new Checks\NoQueriesInControllersCheck(),
                new Checks\StrictTypesCheck(),
                new Checks\ReturnTypesCheck(),
                new Checks\PestStyleCheck(),
                new Checks\PhpUnitStyleCheck(),
            ],
            $app->make(ProjectFiles::class),
        ));

        $this->app->singleton(MutationRecovery::class, fn () => new FilesystemMutationRecovery(
            dirname((string) config('chimera.checkpoints.path')).'/mutation-recovery.json'
        ));

        $this->app->singleton(MutantGenerator::class, fn (Application $app) => new MutantGenerator(
            [
                new Operators\ComparisonOperator(),
                new Operators\LogicalOperator(),
                new Operators\NegationOperator(),
                new Operators\StatusCodeOperator(),
                new Operators\RequiredRuleOperator(),
                new Operators\AuthorizationOperator(),
            ],
            $app->make(FormatPreservingEditor::class),
        ));

        $this->app->singleton(TrustAnalyzer::class, fn (Application $app) => new TrustAnalyzer(
            $app->make(ProjectFiles::class),
            $app->make(MutantGenerator::class),
            new MutationRunner(
                $app->make(ProjectFiles::class),
                $app->make(TestRunner::class),
                $app->make(MutationRecovery::class),
                (int) config('chimera.trust.mutant_timeout', 60),
            ),
            (int) config('chimera.trust.max_mutants', 20),
        ));

        $this->app->bind(ConventionAuditStage::class, fn (Application $app) => new ConventionAuditStage(
            $app->make(ConventionChecker::class),
            $app->make(FileGenerator::class),
            $app->make(Reporter::class),
            $app->make(ProgressTracker::class),
            (int) config('chimera.conventions.audit_retries', 1),
        ));
    }

    private function registerPipeline(): void
    {
        $this->app->bind(MorphPipeline::class, fn (Application $app) => new MorphPipeline(array_map(
            fn (string $stage) => $app->make($stage),
            [
                RecoverInterruptedMutationsStage::class,
                ScanProjectStage::class,
                LoadConventionsStage::class,
                PlanBlueprintStage::class,
                ConfirmBlueprintStage::class,
                GenerateFilesStage::class,
                ConventionAuditStage::class,
                ApplyChangesStage::class,
                MigrateStage::class,
                VerifyAndHealStage::class,
                TrustScoreStage::class,
                SummaryStage::class,
            ],
        )));
    }
}
