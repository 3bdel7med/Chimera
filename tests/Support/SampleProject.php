<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Tests\Support;

/** A small Laravel project with clear habits: Form Requests, services, resources, strict types, Pest. */
final class SampleProject
{
    /** @return array<string, string> */
    public static function files(): array
    {
        $files = [];

        foreach (['Post', 'Comment', 'Tag', 'User'] as $m) {
            $files["app/Models/{$m}.php"] = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\n\nclass {$m} extends Model\n{\n    use HasFactory;\n\n    protected \$fillable = ['name'];\n\n    protected function casts(): array\n    {\n        return [];\n    }\n}\n";
            $files["app/Http/Controllers/{$m}Controller.php"] = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Http\\Controllers;\n\nclass {$m}Controller extends Controller\n{\n    public function __construct(private {$m}Service \$service) {}\n\n    public function store(Store{$m}Request \$request): {$m}Resource\n    {\n        return new {$m}Resource(\$this->service->create(\$request->validated()));\n    }\n\n    public function update(Update{$m}Request \$request, {$m} \$m): {$m}Resource\n    {\n        return new {$m}Resource(\$this->service->update(\$m, \$request->validated()));\n    }\n}\n";
            $files["app/Services/{$m}Service.php"] = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Services;\n\nclass {$m}Service\n{\n    public function create(array \$d): {$m}\n    {\n        return {$m}::create(\$d);\n    }\n\n    public function update({$m} \$m, array \$d): {$m}\n    {\n        return \$m;\n    }\n}\n";
        }

        for ($i = 1; $i <= 4; $i++) {
            $files["database/migrations/2026_01_0{$i}_000000_create_t{$i}_table.php"] = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        \$table->foreignId('user_id')->constrained();\n    }\n\n    public function down(): void\n    {\n    }\n};\n";
        }

        $files['routes/api.php'] = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::middleware('auth:sanctum')->group(function () {\n    Route::apiResource('posts', PostController::class);\n    Route::apiResource('tags', TagController::class);\n});\n";
        $files['tests/Pest.php'] = "<?php\n\npest()->extend(Tests\\TestCase::class)->use(Illuminate\\Foundation\\Testing\\RefreshDatabase::class)->in('Feature');\n";

        foreach (['A', 'B', 'C'] as $t) {
            $files["tests/Feature/{$t}Test.php"] = "<?php\n\nit('works', function () {\n    expect(true)->toBeTrue();\n});\n";
        }

        return $files;
    }
}
