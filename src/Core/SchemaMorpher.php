<?php

namespace Abdelhmed\Chimera\Core;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PhpParser\ParserFactory;
use PhpParser\NodeFinder;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;
use Exception;

class SchemaMorpher
{
    protected array $forbiddenFunctions = [
        'eval', 'exec', 'system', 'shell_exec', 'passthru', 
        'unlink', 'rmdir', 'file_put_contents', 'proc_open',
        'popen', 'pcntl_exec', 'assert', 'rename', 'copy', 'chmod', 'chown', 'symlink',
        'fwrite', 'fputs', 'curl_exec', 'call_user_func', 'call_user_func_array',
        'putenv', 'ini_set', 'unserialize',
    ];

    protected array $allowedPaths = [
        'app/', 'routes/', 'resources/views/', 'resources/js/',
        'database/factories/', 'database/seeders/', 'tests/',
    ];

    protected array $created = [];
    protected array $report = ['migrations' => [], 'written' => [], 'skipped' => [], 'notes' => []];
    protected $parser = null;

    /**
     * Execute the architectural plan, write files, and run migrations safely.
     */
    public function execute(array $plan, string $originalPrompt): array
    {
        if (app()->isProduction()) {
            throw new Exception('Chimera is a development tool and will not run in production.');
        }

        $this->report = ['migrations' => [], 'written' => [], 'skipped' => [], 'notes' => []];

        $migrations = array_values($plan['migrations'] ?? []);
        foreach ($migrations as $i => $migration) {
            $this->migrationFileInfo($migration, $i);
        }
        $files = $this->prepareFiles($plan);

        foreach ($migrations as $i => $migration) {
            $path = $this->writeMigrationFile($migration, $i);

            if ($path !== null) {
                $exitCode = Artisan::call('migrate', ['--path' => $path, '--force' => true]);
                if ($exitCode !== 0) {
                    throw new Exception("Migration [{$path}] failed: " . trim(Artisan::output()));
                }

                $this->report['migrations'][] = $path;
            }
        }

        $injector = new AstCodeInjected();
        foreach ($files as $file) {
            $this->applyFile($file, $injector);
        }

        return $this->report;
    }

    /**
     * Prepare and validate all files before writing to disk.
     */
    protected function prepareFiles(array $plan): array
    {
        $items = [];

        foreach ($plan['code_modifications'] ?? [] as $item) {
            $items[] = ['code', $this->asString($item['file'] ?? ''), $this->asString($item['changes'] ?? ''), strtoupper($this->asString($item['action'] ?? 'CREATE'))];
        }
        foreach ($plan['frontend_modifications'] ?? [] as $frontend) {
            $items[] = ['frontend', $this->asString($frontend['file'] ?? ''), $this->asString($frontend['content'] ?? ''), strtoupper($this->asString($frontend['action'] ?? 'CREATE'))];
        }
        
        foreach ($plan['tests'] ?? [] as $test) {
            $items[] = ['test', $this->asString($test['file'] ?? ($test['path'] ?? '')), $this->asString($test['code'] ?? ''), 'CREATE'];
        }

        $prepared = [];

        foreach ($items as [$kind, $file, $content, $action]) {
            $rel = $this->safePath($file);
            $abs = base_path($rel);

            if (!$this->isFileContentSafe($content, $rel)) {
                throw new Exception("Security Violation: Unsafe code detected in [{$rel}]. Execution aborted before writing anything.");
            }

            $exists = File::exists($abs);
            $modify = in_array($action, ['MODIFY', 'INJECT'], true);

            $mode = match (true) {
                // 1. Handle frontend files FIRST so they NEVER fall into PHP AST merge logic
                $kind === 'frontend' && $exists => 'frontend_safe_write', 
                $kind === 'frontend' => 'write',

                // 2. Backend, routes, and PHP classes logic
                $exists && str_starts_with($rel, 'routes/') => 'routes',
                $exists && $modify => 'merge',
                !$exists && $modify && str_starts_with($rel, 'routes/') => 'new_routes',
                !$exists && $modify && !str_contains($content, 'class ') && !str_contains($rel, 'resources/') => 'skip',
                !$exists => 'write',
                isset($this->created[$rel]) => 'write',
                default => 'skip',
            };

            $prepared[] = compact('kind', 'rel', 'abs', 'content', 'mode');
        }

        return $prepared;
    }

    /**
     * Apply file changes according to resolved mode.
     */
    protected function applyFile(array $file, AstCodeInjected $injector): void
    {
        ['kind' => $kind, 'rel' => $rel, 'abs' => $filePath, 'content' => $newContent, 'mode' => $mode] = $file;

        if ($mode === 'skip') {
            $this->report['skipped'][] = "{$rel} (file already exists or has no target; CREATE refused)";
            return;
        }

        if ($mode === 'merge') {
            try {
                $existingContent = File::get($filePath);
                $mergedContent = $injector->inject($existingContent, $newContent);
                File::put($filePath, $mergedContent);
            } catch (\Throwable $e) {
                $this->report['skipped'][] = "{$rel}: could not merge ({$e->getMessage()})";
                return;
            }
        } elseif ($mode === 'routes') {
            if (!$this->appendRoutes($filePath, $newContent)) {
                $this->report['skipped'][] = "{$rel} (routes already present or not valid PHP)";
                return;
            }
        } elseif ($mode === 'new_routes') {
            $snippet = trim(preg_replace('/^\s*<\?php\s*/', '', $newContent));
            $snippet = trim(str_replace('use Illuminate\Support\Facades\Route;', '', $snippet));
            $this->ensureDirectoryExists($filePath);
            File::put($filePath, "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n{$snippet}\n");
            $this->created[$rel] = true;
            $this->report['notes'][] = "{$rel} was created.";
        } elseif ($mode === 'frontend_inject') {
            // [Frontend Injection Handler] Intelligently injects or merges sections into Blade or Vue files without destroying existing code
            $existingContent = File::get($filePath);
            $injectedContent = $this->injectFrontendContent($existingContent, $newContent, $rel);
            File::put($filePath, $injectedContent);
            $this->report['notes'][] = "{$rel} was successfully updated via frontend injection.";
       } elseif ($mode === 'frontend_safe_write' || $mode === 'frontend_inject') {
            // Direct clean write/update for frontend files without creating annoying .bak files
            $this->ensureDirectoryExists($filePath);
            File::put($filePath, $newContent);
            $this->created[$rel] = true;
            $this->report['notes'][] = "{$rel} was successfully updated.";
            }
            else {
            $this->ensureDirectoryExists($filePath);
            File::put($filePath, $newContent);
            $this->created[$rel] = true;
        }

        $this->report['written'][] = $rel;
    }

    /**
     * Intelligently inject content into Blade or Vue/React files.
     */
    protected function injectFrontendContent(string $existing, string $incoming, string $rel): string
    {
        // For Blade files, check for sections like @section or @push to inject inside them safely
        if (str_ends_with($rel, '.blade.php')) {
            // If incoming has sections, try to merge matching sections
            if (preg_match('/@section\(\s*[\'"]([^\'"]+)[\'"]\s*\)(.*?)@endsection/s', $incoming, $match)) {
                $sectionName = $match[1];
                $incomingBody = $match[2];

                // Check if existing file has the same section
                $pattern = '/@section\(\s*[\'"]' . preg_quote($sectionName, '/') . '[\'"]\s*\)(.*?)@endsection/s';
                if (preg_match($pattern, $existing)) {
                    // Append or merge inside the section before @endsection
                    return preg_replace($pattern, "@section('{$sectionName}')\$1\n{$incomingBody}\n@endsection", $existing);
                }
            }
            // Fallback: append incoming content to the end of the Blade file if no section match
            return $existing . "\n\n" . $incoming;
        }

        // For Vue files (.vue), inject into <template> or <script setup> blocks
        if (str_ends_with($rel, '.vue')) {
            // If incoming has a template block, merge inner contents
            if (preg_match('/<template>(.*?)<\/template>/s', $incoming, $incomingTemplate)) {
                $templateBody = $incomingTemplate[1];
                if (str_contains($existing, '</template>')) {
                    // Insert right before closing template tag
                    return str_replace('</template>', "\n    " . trim($templateBody) . "\n</template>", $existing);
                }
            }
        }

        // Default fallback: return existing + incoming if no specific rules matched
        return $existing . "\n\n" . $incoming;
    }

    protected function appendRoutes(string $filePath, string $snippet): bool
    {
        $snippet = trim(preg_replace('/^\s*<\?php\s*/', '', $snippet));
        if ($snippet === '') {
            return false;
        }

        $printer = new Standard();

        try {
            $existing = array_map(
                fn ($stmt) => $printer->prettyPrint([$stmt]),
                $this->phpParser()->parse(File::get($filePath)) ?? []
            );
            $incoming = $this->phpParser()->parse("<?php\n" . $snippet) ?? [];
        } catch (Error $e) {
            return false;
        }

        $new = [];
        foreach ($incoming as $stmt) {
            $code = $printer->prettyPrint([$stmt]);
            if (!in_array($code, $existing, true)) {
                $new[] = $code;
            }
        }

        if (empty($new)) {
            return false;
        }

        File::append($filePath, "\n" . implode("\n", $new) . "\n");

        return true;
    }

    protected function writeMigrationFile(array $migration, int $offset = 0): ?string
    {
        $info = $this->migrationFileInfo($migration, $offset);

        if ($info === null) {
            $this->report['skipped'][] = 'migration ' . ($migration['name'] ?? 'unknown') . ' (already exists)';
            return null;
        }

        [$name, $fullCode] = $info;

        $migrationPath = database_path("migrations/{$name}");
        $this->ensureDirectoryExists($migrationPath);
        File::put($migrationPath, $fullCode);

        return "database/migrations/{$name}";
    }

    protected function migrationFileInfo(array $migration, int $offset = 0): ?array
    {
        $rawName = $this->asString($migration['name'] ?? ('custom_migration_' . time() . '.php'));
        $schema = $this->asString($migration['schema'] ?? '');
        $down = $this->asString($migration['down'] ?? '');

        $suffix = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($rawName));
        $suffix = preg_replace('/\.php$/i', '', $suffix);
        $suffix = trim(preg_replace('/[^a-z0-9_]+/', '_', strtolower($suffix)), '_');

        if (strlen($suffix) < 3 || strlen($suffix) > 100) {
            throw new Exception("Invalid migration name [{$rawName}]. Execution aborted.");
        }

        if (glob(database_path("migrations/*_{$suffix}.php"))) {
            return null;
        }

        $fullCode = $this->buildMigrationCode($schema, $down);

        if (!$this->isSchemaCodeSafe($fullCode)) {
            throw new Exception("Security Violation: Dangerously crafted migration schema detected in [{$rawName}]. Execution aborted.");
        }

        return [now()->addSeconds($offset)->format('Y_m_d_His') . "_{$suffix}.php", $fullCode];
    }

    protected function buildMigrationCode(string $schema, string $down): string
    {
        $down = trim($down) !== '' ? $down : '// Auto-generated rollback by Chimera';

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        {$schema}
    }

    public function down(): void
    {
        {$down}
    }
};
PHP;
    }

    protected function isSchemaCodeSafe(string $code): bool
    {
        try {
            $ast = $this->phpParser()->parse($code);

            if (!$ast) {
                return false;
            }

            $nodeFinder = new NodeFinder();
            $funcCalls = $nodeFinder->findInstanceOf($ast, FuncCall::class);

            foreach ($funcCalls as $funcCall) {
                if ($funcCall->name instanceof \PhpParser\Node\Name) {
                    $funcName = strtolower($funcCall->name->toString());
                    if (in_array($funcName, $this->forbiddenFunctions)) {
                        return false;
                    }
                }
            }

            return !$this->hasDangerousNode($ast);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function isFileContentSafe(string $content, string $rel): bool
    {
        if (str_ends_with($rel, '.blade.php')) {
            return $this->isBladeSafe($content);
        }
        if (str_ends_with($rel, '.php')) {
            return $this->isPhpCodeSafe($content);
        }

        return true;
    }

    protected function isPhpCodeSafe(string $code): bool
    {
        $code = preg_replace('/^\s*<\?php\s*/', '', $code);

        foreach (["<?php\n{$code}", "<?php\nclass __ChimeraFragment {\n{$code}\n}"] as $source) {
            $ast = $this->parseOrNull($source);
            if ($ast !== null) {
                return !$this->hasDangerousNode($ast);
            }
        }

        return false;
    }

    protected function isBladeSafe(string $content): bool
    {
        $segments = [];
        foreach (['/@php\b(.*?)@endphp/s', '/@php\s*\((.*)\)/m', '/<\?php(.*?)(?:\?>|$)/s', '/\{\{(.*?)\}\}/s', '/\{!!(.*?)!!\}/s'] as $pattern) {
            if (preg_match_all($pattern, $content, $m)) {
                $segments = array_merge($segments, $m[1]);
            }
        }

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $ast = $this->parseOrNull("<?php\n{$segment};") ?? $this->parseOrNull("<?php\n{$segment}");
            if ($ast !== null && $this->hasDangerousNode($ast)) {
                return false;
            }
        }

        return true;
    }

    protected function hasDangerousNode(array $ast): bool
    {
        return (new NodeFinder())->findFirst($ast, fn ($n) => $this->isDangerousNode($n)) !== null;
    }

    protected function isDangerousNode(Node $n): bool
    {
        if ($n instanceof Node\Expr\Eval_ || $n instanceof Node\Expr\ShellExec || $n instanceof Node\Expr\Include_) {
            return true;
        }

        if ($n instanceof FuncCall && $n->name instanceof Node\Name) {
            return in_array(strtolower($n->name->getLast()), $this->forbiddenFunctions, true);
        }

        if ($n instanceof Node\Expr\StaticCall && $n->name instanceof Node\Identifier
            && strtolower($n->name->toString()) === 'unprepared') {
            return true;
        }

        if (($n instanceof Node\Expr\New_ || $n instanceof Node\Expr\StaticCall)
            && $n->class instanceof Node\Name && strtolower($n->class->getLast()) === 'process') {
            return true;
        }

        return false;
    }

    protected function parseOrNull(string $code): ?array
    {
        try {
            return $this->phpParser()->parse($code);
        } catch (Error $e) {
            return null;
        }
    }

    protected function phpParser()
    {
        return $this->parser ??= (new ParserFactory())->createForHostVersion();
    }

    protected function safePath(string $relative): string
    {
        $path = ltrim(str_replace('\\', '/', trim($relative)), '/');
        $path = preg_replace('#^\./#', '', $path);

        if ($path === ''
            || str_starts_with(str_replace('\\', '/', trim($relative)), '/')
            || str_contains($path, "\0")
            || preg_match('#^[A-Za-z]:#', $path)
            || preg_match('#(^|/)\.\.(/|$)#', $path)) {
            throw new Exception("Invalid file path [{$relative}]. Execution aborted.");
        }

        foreach ((array) config('chimera.allowed_paths', $this->allowedPaths) as $root) {
            if (str_starts_with($path, $root)) {
                return $path;
            }
        }

        throw new Exception("Path [{$path}] is outside the allowed directories. Execution aborted.");
    }

    protected function asString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    protected function ensureDirectoryExists(string $filePath): void
    {
        $directory = dirname($filePath);
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
    }
}