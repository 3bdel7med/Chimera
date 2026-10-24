<?php

return [

    /*
    | The LLM backend. Only "gemini" ships today; add your own by implementing
    | Abdelhmed\Chimera\Contracts\LlmClient and rebinding it in a service provider.
    */
    'driver' => env('CHIMERA_DRIVER', 'gemini'),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('CHIMERA_GEMINI_MODEL', 'gemini-2.7-flash'),
        'base_url' => env('CHIMERA_GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('CHIMERA_TIMEOUT', 180),
        'max_output_tokens' => (int) env('CHIMERA_MAX_TOKENS', 32768),
        'temperature' => (float) env('CHIMERA_TEMPERATURE', 0.2),
        'retries' => 3,
    ],

    'generation' => [
        // Hard cap on the number of files a single blueprint may contain.
        'max_files' => 42,
        // Files generated per AI call. Big batches are split automatically if the model truncates.
        'files_per_batch' => 4,
        // How many times a batch is re-requested when the AI returns PHP that does not parse.
        'max_syntax_retries' => 2,
    ],

    'safety' => [
        // The AI may only write inside these top-level directories.
        'writable_paths' => ['app', 'routes', 'database', 'resources', 'tests', 'config', 'lang'],
        // Any path containing one of these segments is rejected (.env* files are always rejected).
        'protected_segments' => ['.git', 'vendor', 'node_modules', 'storage'],
    ],

    // Run the generated migrations (only the generated ones) after writing files.
    'migrate' => true,

    'testing' => [
        'enabled' => true,
        // How many times Chimera asks the AI to fix failing generated tests.
        'max_heal_attempts' => 2,
        'timeout' => 300,
        'env' => [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
        ],
    ],

    // Limits for the project summary sent to the model.
    'context' => [
        'max_models' => 60,
        'max_controllers' => 60,
        'max_routes_per_file' => 80,
    ],

    'conventions' => [
        // Teach the AI your project's habits and check its output against them.
        'enabled' => true,
        // Created by `php artisan chimera:learn`. Commit it: your team shares the same rules.
        'path' => base_path('.chimera/conventions.json'),
        'max_files' => 400,
        // How many times the AI is asked to fix generated code that breaks an enforceable convention.
        'audit_retries' => 1,
    ],

    'trust' => [
        // After the generated tests pass, plant deliberate bugs in the generated code and count how many the tests catch.
        'enabled' => true,
        'max_mutants' => 20,
        // Seconds allowed for the tests to react to one deliberate bug.
        'mutant_timeout' => 60,
    ],

    'prompts' => [
        // Drop blueprint.system.stub / generate.system.stub / heal.system.stub here to override the defaults.
        'path' => base_path('stubs/chimera'),
    ],

    // Where checkpoints (backups used by chimera:rollback) are stored.
    'checkpoints' => [
        'path' => storage_path('chimera/checkpoints'),
    ],
];
