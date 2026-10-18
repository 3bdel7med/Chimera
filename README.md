```markdown
# Chimera 🧬

[![Latest Version on Packagist](https://img.shields.io/packagist/v/abdelhmed/chimera.svg?style=flat-square)](https://packagist.org/packages/abdelhmed/chimera)
[![Total Downloads](https://img.shields.io/packagist/dt/abdelhmed/chimera.svg?style=flat-square)](https://packagist.org/packages/abdelhmed/chimera)
[![License](https://img.shields.io/packagist/l/abdelhmed/chimera.svg?style=flat-square)](LICENSE.md)

An autonomous, AI-powered feature engineering and self-mutating code package designed exclusively for Laravel applications. 

Powered natively by **Google Gemini API** and AST (`nikic/php-parser`) code analysis, Chimera parses your application architecture, understands existing business logic, and programmatically generates, refactors, and injects full-stack components—from Eloquent models and dynamic migrations to background jobs, Pest tests, and Blade views.

<video src="https://raw.githubusercontent.com/3bdel7med/Chimera/main/assets/chimera-demo-60s-clean.mp4" controls width="100%"></video>

> ⚠️ **Development Tool Only:** Chimera writes PHP files, injects routes, and executes migrations. It explicitly refuses to run in `production` environments. Always review changes before committing.

---

## ✨ Highlights & Architecture

- **Native Google Gemini Integration:** Engineered specifically around Google Gemini's extended context window and fast structured response generation.
- **Deep AST Codebase Parsing:** Uses `nikic/php-parser` to parse PHP Abstract Syntax Trees. It maps methods, class properties, and Eloquent relationships directly—avoiding fragile regex or crude text replacing.
- **Smart Context & Token Optimization:** Selects and sanitizes only relevant database schemas, models, and routes before invoking the LLM, reducing token consumption while preventing sensitive file leaks (e.g., `.env`).
- **Deterministic Multi-Step Execution:** Breaks complex feature prompts (e.g., multi-vendor checkout or dynamic authentication) into phased implementation blueprints covering Models, Migrations, Controllers, Events, Jobs, and Views.
- **Self-Healing Pest/PHPUnit Tests:** Executes generated tests automatically on isolated SQLite instances and feeds failure traces back into Gemini for auto-correction.
- **Automated Git Checkpoints & Time Travel:** Auto-commits state before applying changes, allowing seamless single-command rollbacks—including database schema state.

---

## 📦 Installation

Install Chimera as a development dependency via Composer:

```bash
composer require --dev abdelhmed/chimera

```

Publish the package configuration file:

```bash
php artisan vendor:publish --tag=chimera-config

```

Add your Google Gemini API key to your `.env` file:

```env
GEMINI_API_KEY=your_google_gemini_api_key_here

```

### Requirements

* **PHP 8.2+**
* **Laravel 10.x or 11.x**
* Clean Git repository working tree (`git status` must be clean before running mutations).
* A valid [Google Gemini API Key](https://aistudio.google.com/).

---

## 🛠️ Usage Commands

### 1. Morph Application (`chimera:morph`)

Describe the feature or component you want to build or refactor in natural language:

```bash
php artisan chimera:morph "Build a multi-vendor order checkout with Stripe integration and queued notifications"

```

**Execution Pipeline:**

1. **AST Analysis:** Scans your project structure, Eloquent models, and active routes.
2. **AI Blueprinting:** Queries Gemini to build a phased architectural plan.
3. **Impact Analysis:** Displays planned file modifications and structural changes in your terminal.
4. **Interactive Confirmation:** Asks for explicit confirmation before altering your disk or running database migrations.
5. **Self-Correction & Test Verification:** Runs Pest/PHPUnit test suites and attempts automated fixes if assertions fail.
6. **Git Checkpoint:** Saves a clean Git commit checkpoint.

#### Dry-Run Mode

Preview the execution plan and impact table without modifying files or executing migrations:

```bash
php artisan chimera:morph "Add product inventory system" --dry-run

```

---

### 2. Rollback Last Change (`chimera:rollback`)

Revert the last architectural mutation applied by Chimera, including reverted database migrations:

```bash
php artisan chimera:rollback

```

> *Note: Rollback will refuse to execute if there are uncommitted manual changes in your working directory.*

---

### 3. Interactive Time Travel (`chimera:travel`)

Inspect and travel back to any historical Chimera checkpoint interactively:

```bash
php artisan chimera:travel

```

---

## 🛡️ Security & Code Guardrails

Chimera implements strict static analysis guardrails prior to file injection:

* **AST Code Sanitization:** Generated PHP fragments are parsed via AST before disk operations. Dangerous function executions (e.g., `eval()`, `exec()`, `shell_exec()`, `unlink()`, `DB::unprepared()`) immediately abort execution.
* **Path Whitelisting:** File modifications are restricted strictly to:
* `app/`
* `routes/`
* `resources/`
* `database/factories/`
* `database/seeders/`
* `tests/`


* **Non-Destructive AST Injections:** Existing methods are never silently overwritten. New relationships and members are safely merged into existing classes via AST manipulation.

---

## ⚙️ Configuration Options

Custom settings are located in `config/chimera.php`:

```php
return [
    'model' => env('CHIMERA_GEMINI_MODEL', 'gemini-1.5-flash'),
    'max_tokens' => 8192,
    'timeout' => 120,
    'test_env' => 'sqlite',
    'allowed_paths' => [
        'app',
        'routes',
        'resources',
        'database/factories',
        'database/seeders',
        'tests',
    ],
];

```

---

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE.md) for more details.

---

**Author:** [Abdelhamed Fathy](https://www.google.com/search?q=https://github.com/3bdel7med)

*Full-Stack Software Engineer specializing in Laravel, Python, & AI Integrations.*
