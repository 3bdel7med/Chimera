<?php

use Abdelhmed\Chimera\Exceptions\InjectionException;
use Abdelhmed\Chimera\Injection\ClassMembersInjector;
use Abdelhmed\Chimera\Injection\FormatPreservingEditor;
use Abdelhmed\Chimera\Injection\ImportMerger;
use Abdelhmed\Chimera\Injection\RouteInjector;
use Abdelhmed\Chimera\Injection\SnippetParser;

const USER_MODEL = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class User
{
    use HasFactory;

    protected $fillable = ['name', 'email'];

    public function profile()
    {
        return $this->hasOne(Profile::class);
    }
}
PHP;

const USER_SNIPPET = <<<'PHP'
<?php
use App\Models\Post;
use Illuminate\Notifications\Notifiable;

class User
{
    use Notifiable;

    protected $fillable = ['email', 'role'];

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function profile()
    {
        return 'must not replace the existing method';
    }
}
PHP;

function members(): ClassMembersInjector
{
    return new ClassMembersInjector(new FormatPreservingEditor(), new ImportMerger(), new SnippetParser());
}

it('adds new members to a class, keeps existing ones and merges imports, traits and list properties', function () {
    $out = members()->inject(USER_MODEL, USER_SNIPPET);

    expect($out)->toContain('use App\Models\Post;');
    expect($out)->toContain('use HasFactory, Notifiable;');
    expect($out)->toContain("'role'");
    expect($out)->toContain('function posts()');
    expect($out)->toContain('return $this->hasOne(Profile::class);');
    expect(substr_count($out, 'function profile'))->toBe(1);
    expect($out)->toContain("}\n\n    public function posts()");
    expect(substr_count($out, "'email'"))->toBe(1);
});

it('is idempotent for classes and for routes', function () {
    $once = members()->inject(USER_MODEL, USER_SNIPPET);
    expect(members()->inject($once, USER_SNIPPET))->toBe($once);

    $injector = new RouteInjector(new FormatPreservingEditor(), new ImportMerger(), new SnippetParser());
    $routes = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/ping', fn () => 'pong');\n";
    $snippet = "use App\\Http\\Controllers\\PostController;\nuse Illuminate\\Support\\Facades\\Route;\nRoute::apiResource('posts', PostController::class);\nRoute::get('/ping', fn () => 'pong');";

    $out = $injector->inject($routes, $snippet);
    expect($out)->toContain('use App\Http\Controllers\PostController;');
    expect(substr_count($out, 'Route::get'))->toBe(1);
    expect(substr_count($out, 'use Illuminate\Support\Facades\Route;'))->toBe(1);
    expect($injector->inject($out, $snippet))->toBe($out);
});

it('fails clearly on a snippet that is not a class skeleton or not valid PHP', function () {
    expect(fn () => members()->inject(USER_MODEL, '<?php function nope() {}'))->toThrow(InjectionException::class);
    expect(fn () => members()->inject(USER_MODEL, '<?php class User { public function ( }'))->toThrow(InjectionException::class);
});
