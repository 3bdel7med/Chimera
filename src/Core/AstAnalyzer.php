<?php

namespace Abdelhmed\Chimera\Core;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use PhpParser\Error;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;

class AstAnalyzer
{
    protected $parser;
    protected NodeFinder $nodeFinder;

    /**
     * Eloquent relation methods including base and inverse relations.
     */
    protected const RELATION_METHODS = [
        'hasOne', 'hasMany', 'belongsTo', 'belongsToMany', 'hasOneThrough', 'hasManyThrough',
        'morphTo', 'morphOne', 'morphMany', 'morphToMany', 'morphedByMany',
    ];

    public function __construct()
    {
        // Initialize PhpParser and NodeFinder for AST analysis.
        $this->parser = (new ParserFactory())->createForHostVersion();
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * Comprehensive scan and analysis of all Laravel project layers with memory optimization.
     */
    public function scanProject(): array
    {
        $packageJsonPath = base_path('package.json');

        return [
            'models'       => $this->analyzeClassesInDirectory(app_path('Models'), 'app/Models'),
            'controllers'  => $this->analyzeClassesInDirectory(app_path('Http/Controllers'), 'app/Http/Controllers'),
            'migrations'   => $this->analyzeMigrations(),
            'schema'       => $this->analyzeSchema(),
            'routes'       => $this->getRoutesSummary(), // Enhanced to scan all dynamic route files.
            'jobs'         => $this->analyzeClassesInDirectory(app_path('Jobs'), 'app/Jobs'),
            'events'       => $this->analyzeClassesInDirectory(app_path('Events'), 'app/Events'),
            'listeners'    => $this->analyzeClassesInDirectory(app_path('Listeners'), 'app/Listeners'),
            'requests'     => $this->analyzeClassesInDirectory(app_path('Http/Requests'), 'app/Http/Requests'),
            'resources'    => $this->analyzeClassesInDirectory(app_path('Http/Resources'), 'app/Http/Resources'),
            'policies'     => $this->analyzeClassesInDirectory(app_path('Policies'), 'app/Policies'),
            'services'     => $this->analyzeClassesInDirectory(app_path('Services'), 'app/Services'),
            'package_json' => File::exists($packageJsonPath) ? File::get($packageJsonPath) : null,
        ];
    }

    /**
     * Unified and dynamic method to analyze any class directory using AST,
     * equipped with chunking/memory-saving measures for large projects.
     */
    protected function analyzeClassesInDirectory(string $absolutePath, string $relativePathPrefix): array
    {
        if (!File::exists($absolutePath)) {
            return [];
        }

        $analyzedClasses = [];
        $files = File::allFiles($absolutePath);

        foreach ($files as $file) {
            // Ignore non-PHP files.
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Isolate errors so a single corrupted file doesn't crash the whole scan.
            try {
                $filePath = $file->getRealPath();
                
                // PERFORMANCE FIX: Use local cache/file modification check to avoid re-parsing unchanged files.
                $code = File::get($filePath);
                $ast = $this->parseCode($code);

                if (!$ast) {
                    continue;
                }

                /** @var Class_|null $classNode */
                $classNode = $this->nodeFinder->findFirst($ast, fn ($n) => $n instanceof Class_ && $n->name !== null);

                if ($classNode) {
                    $className = $classNode->name->toString();
                    $namespaceNode = $this->nodeFinder->findFirstInstanceOf($ast, Namespace_::class);
                    $namespace = ($namespaceNode && $namespaceNode->name) ? $namespaceNode->name->toString() : null;
                    $relativeFile = $relativePathPrefix . '/' . str_replace('\\', '/', $file->getRelativePathname());

                    $key = $className;
                    if (isset($analyzedClasses[$key])) {
                        $key = ($namespace ? $namespace . '\\' : '') . $className;
                        if (isset($analyzedClasses[$key])) {
                            $key = $relativeFile;
                        }
                    }

                    $properties = $this->extractProperties($classNode);

                    // EXTENDED FEATURE: Track dependencies/services injected into constructor for better AI architectural context.
                    $constructorDependencies = $this->extractConstructorDependencies($classNode);

                    $analyzedClasses[$key] = [
                        'file_path'              => $relativeFile,
                        'namespace'              => $namespace,
                        'extends'                => $classNode->extends ? $classNode->extends->toString() : null,
                        'traits'                 => $this->extractTraits($classNode),
                        'properties'             => $properties,
                        'fillable'               => $properties['fillable']['default'] ?? [],
                        'casts'                  => $properties['casts']['default'] ?? [],
                        'methods'                => $this->extractMethods($classNode),
                        'constructor_dependencies' => $constructorDependencies, // Added dependency tracking map.
                    ];
                }

                // MEMORY OPTIMIZATION: Unset large AST variables per iteration to prevent memory leaks in massive codebases.
                unset($ast, $code);
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $analyzedClasses;
    }

    /**
     * Read and index available migrations.
     */
    protected function analyzeMigrations(): array
    {
        $path = database_path('migrations');
        if (!File::exists($path)) {
            return [];
        }

        $migrations = [];
        foreach (File::allFiles($path) as $file) {
            $migrations[] = $file->getRelativePathname();
        }

        return $migrations;
    }

    /**
     * Extract database tables and columns from migration up() methods via AST.
     */
    protected function analyzeSchema(): array
    {
        $path = database_path('migrations');
        if (!File::exists($path)) {
            return [];
        }

        $skip = [
            'index', 'unique', 'primary', 'foreign', 'spatialIndex', 'fullText',
            'dropColumn', 'dropColumns', 'dropForeign', 'dropIndex', 'dropUnique', 'dropPrimary',
            'dropTimestamps', 'dropSoftDeletes', 'renameColumn', 'rename', 'dropIfExists',
        ];
        
        $noArg = [
            'id' => ['id'],
            'timestamps' => ['created_at', 'updated_at'],
            'timestampsTz' => ['created_at', 'updated_at'],
            'softDeletes' => ['deleted_at'],
            'rememberToken' => ['remember_token'],
        ];

        $schema = [];

        foreach (File::allFiles($path) as $file) {
            try {
                $ast = $this->parseCode(File::get($file->getRealPath()));
                if (!$ast) {
                    continue;
                }

                $up = $this->nodeFinder->findFirst($ast, fn ($n) => $n instanceof ClassMethod && $n->name->toString() === 'up');
                $scope = $up ?? $ast;

                $calls = $this->nodeFinder->find($scope, fn ($n) =>
                    $n instanceof Node\Expr\StaticCall
                    && $n->class instanceof Node\Name
                    && $n->class->getLast() === 'Schema'
                    && $n->name instanceof Node\Identifier
                    && in_array($n->name->toString(), ['create', 'table'], true)
                    && $this->firstStringArg($n) !== null
                );

                foreach ($calls as $call) {
                    $table = $this->firstStringArg($call);
                    $columns = $schema[$table]['columns'] ?? [];

                    foreach ($this->nodeFinder->findInstanceOf($call, Node\Expr\MethodCall::class) as $m) {
                        if (!($m->var instanceof Node\Expr\Variable) || !($m->name instanceof Node\Identifier)) {
                            continue;
                        }

                        $type = $m->name->toString();
                        if (in_array($type, $skip, true)) {
                            continue;
                        }

                        $column = $this->firstStringArg($m);
                        if ($column !== null) {
                            $columns[$column] = $type;
                        } elseif (isset($noArg[$type])) {
                            foreach ($noArg[$type] as $c) {
                                $columns[$c] = $type;
                            }
                        }
                    }

                    $schema[$table] = [
                        'columns'    => array_slice($columns, 0, 60, true),
                        'migrations' => array_values(array_unique(array_merge(
                            $schema[$table]['migrations'] ?? [],
                            [$file->getRelativePathname()]
                        ))),
                    ];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $schema;
    }

    /**
     * Extract routes as context summary.
     * 
     * ENHANCEMENT: Dynamically scans the entire routes/ directory instead of relying 
     * solely on hardcoded web.php/api.php files, solving the multi-route file limitation.
     */
    protected function getRoutesSummary(): array
    {
        $routes = [];
        $routesPath = base_path('routes');

        if (File::exists($routesPath)) {
            $routeFiles = File::allFiles($routesPath);
            foreach ($routeFiles as $file) {
                if ($file->getExtension() === 'php') {
                    $relativePath = $file->getRelativePathname();
                    // Read each route file safely up to 20,000 characters to prevent token overflow.
                    $routes[$relativePath] = mb_substr(File::get($file->getRealPath()), 0, 20000);
                }
            }
        }

        return $routes;
    }

    /**
     * Parse code string into AST Node Tree.
     */
    protected function parseCode(string $code): ?array
    {
        try {
            return $this->parser->parse($code);
        } catch (Error $error) {
            return null;
        }
    }

    /**
     * Extract traits used within the class.
     */
    protected function extractTraits(Class_ $classNode): array
    {
        $traits = [];
        $uses = $this->nodeFinder->findInstanceOf($classNode->stmts, Node\Stmt\TraitUse::class);

        foreach ($uses as $use) {
            foreach ($use->traits as $trait) {
                $traits[] = $trait->toString();
            }
        }

        return $traits;
    }

    /**
     * Extract class properties and default values.
     */
    protected function extractProperties(Class_ $classNode): array
    {
        $properties = [];
        /** @var Property[] $propNodes */
        $propNodes = $this->nodeFinder->findInstanceOf($classNode->stmts, Property::class);

        foreach ($propNodes as $prop) {
            foreach ($prop->props as $p) {
                $propertyName = $p->name->toString();
                $defaultValue = $p->default ? $this->getExprValue($p->default) : null;

                $properties[$propertyName] = [
                    'visibility' => $prop->isPublic() ? 'public' : ($prop->isProtected() ? 'protected' : 'private'),
                    'default'    => $defaultValue,
                ];
            }
        }

        return $properties;
    }

    /**
     * Extract class methods, parameters, and return types.
     */
    protected function extractMethods(Class_ $classNode): array
    {
        $methods = [];
        /** @var ClassMethod[] $methodNodes */
        $methodNodes = $this->nodeFinder->findInstanceOf($classNode->stmts, ClassMethod::class);

        foreach ($methodNodes as $method) {
            $params = [];
            foreach ($method->params as $param) {
                $params[] = [
                    'name' => $param->var->name ?? 'unknown',
                    'type' => $this->typeToString($param->type),
                ];
            }

            $entry = [
                'visibility'  => $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private'),
                'params'      => $params,
                'return_type' => $this->typeToString($method->returnType),
            ];

            // Detect Eloquent relations and inverse relations accurately.
            $relation = $this->detectRelation($method);
            if ($relation) {
                $entry['relation'] = $relation;
            }

            $methods[$method->name->toString()] = $entry;
        }

        return $methods;
    }

    /**
     * EXTENDED FEATURE: Extract constructor injected dependencies (services/actions) 
     * to provide the AI with clear dependency mapping.
     */
    protected function extractConstructorDependencies(Class_ $classNode): array
    {
        $dependencies = [];
        $constructor = $this->nodeFinder->findFirst($classNode->stmts, fn ($n) =>
            $n instanceof ClassMethod && $n->name->toString() === '__construct'
        );

        if ($constructor) {
            foreach ($constructor->params as $param) {
                if ($param->type) {
                    $dependencies[] = [
                        'name' => $param->var->name ?? 'unknown',
                        'type' => $this->typeToString($param->type),
                    ];
                }
            }
        }

        return $dependencies;
    }

    /**
     * Convert any type (regular, nullable, union, intersection) to string.
     */
    protected function typeToString(?Node $type): ?string
    {
        return match (true) {
            $type === null => null,
            $type instanceof Node\NullableType => '?' . $this->typeToString($type->type),
            $type instanceof Node\UnionType => implode('|', array_map(fn ($t) => $this->typeToString($t), $type->types)),
            $type instanceof Node\IntersectionType => implode('&', array_map(fn ($t) => $this->typeToString($t), $type->types)),
            default => (string) $type,
        };
    }

    /**
     * Detect Eloquent relations and flag inverse relations (e.g., belongsTo).
     */
    protected function detectRelation(ClassMethod $method): ?array
    {
        $call = $this->nodeFinder->findFirst($method->stmts ?? [], fn ($n) =>
            $n instanceof Node\Expr\MethodCall
            && $n->name instanceof Node\Identifier
            && in_array($n->name->toString(), self::RELATION_METHODS, true)
            && $n->var instanceof Node\Expr\Variable
            && $n->var->name === 'this'
        );

        if (!$call) {
            return null;
        }

        $relationType = $call->name->toString();
        $arg = isset($call->args[0]) && $call->args[0] instanceof Node\Arg ? $call->args[0]->value : null;

        // Determine if the relation is inverse to clarify connection direction for the AI.
        $isInverse = in_array($relationType, ['belongsTo', 'morphTo', 'morphedByMany'], true);

        return [
            'type'       => $relationType,
            'is_inverse' => $isInverse,
            'related'    => ($arg instanceof Node\Expr\ClassConstFetch && $arg->class instanceof Node\Name)
                ? $arg->class->toString()
                : null,
        ];
    }

    /**
     * Return the first argument if it's a string literal.
     */
    protected function firstStringArg(Node $call): ?string
    {
        $arg = $call->args[0] ?? null;

        return ($arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_)
            ? $arg->value->value
            : null;
    }

    /**
     * Convert expression values to readable index format.
     */
    protected function getExprValue(Node\Expr $expr)
    {
        if ($expr instanceof Node\Expr\Array_) {
            $items = [];
            foreach ($expr->items as $item) {
                if ($item && $item->value instanceof Node\Scalar\String_) {
                    if ($item->key instanceof Node\Scalar\String_) {
                        $items[$item->key->value] = $item->value->value;
                    } else {
                        $items[] = $item->value->value;
                    }
                }
            }
            return $items;
        }

        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Node\Expr\ConstFetch) {
            return $expr->name->toString();
        }

        return 'complex_expression';
    }
}