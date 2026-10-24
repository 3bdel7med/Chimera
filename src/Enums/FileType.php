<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Enums;

/**
 * The declaration order is the order files are generated and applied in:
 * dependencies first (config, migrations, models...) and tests last.
 */
enum FileType: string
{
    case Config = 'config';
    case Other = 'other';
    case Migration = 'migration';
    case Model = 'model';
    case Factory = 'factory';
    case Seeder = 'seeder';
    case Service = 'service';
    case Policy = 'policy';
    case Middleware = 'middleware';
    case Request = 'request';
    case Resource = 'resource';
    case Event = 'event';
    case Listener = 'listener';
    case Job = 'job';
    case Notification = 'notification';
    case Controller = 'controller';
    case Route = 'route';
    case View = 'view';
    case Frontend = 'frontend';
    case Test = 'test';

    public function label(): string
    {
        return match ($this) {
            self::Request => 'Form Request',
            self::Resource => 'API Resource',
            default => ucfirst($this->value),
        };
    }

    public function order(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    public static function fromLoose(mixed $value): ?self
    {
        $key = preg_replace('/[^a-z]/', '', strtolower((string) (is_scalar($value) ? $value : '')));

        return match ($key) {
            'config', 'configuration' => self::Config,
            'migration', 'migrations' => self::Migration,
            'model', 'models', 'eloquentmodel' => self::Model,
            'factory' => self::Factory,
            'seeder' => self::Seeder,
            'service', 'services', 'action' => self::Service,
            'policy' => self::Policy,
            'middleware' => self::Middleware,
            'request', 'formrequest' => self::Request,
            'resource', 'apiresource', 'jsonresource' => self::Resource,
            'event' => self::Event,
            'listener' => self::Listener,
            'job' => self::Job,
            'notification' => self::Notification,
            'controller' => self::Controller,
            'route', 'routes' => self::Route,
            'view', 'blade' => self::View,
            'frontend', 'frontendview', 'vue', 'js', 'component' => self::Frontend,
            'test', 'tests', 'testfile', 'featuretest', 'unittest' => self::Test,
            'other' => self::Other,
            default => null,
        };
    }

    public static function guessFromPath(string $path): self
    {
        $p = str_replace('\\', '/', $path);

        return match (true) {
            str_starts_with($p, 'database/migrations/') => self::Migration,
            str_starts_with($p, 'database/factories/') => self::Factory,
            str_starts_with($p, 'database/seeders/') => self::Seeder,
            str_starts_with($p, 'routes/') => self::Route,
            str_starts_with($p, 'config/') => self::Config,
            str_starts_with($p, 'tests/') => self::Test,
            str_starts_with($p, 'resources/views/') => self::View,
            str_starts_with($p, 'resources/js/'), str_starts_with($p, 'resources/css/') => self::Frontend,
            str_starts_with($p, 'app/Models/') => self::Model,
            str_starts_with($p, 'app/Http/Controllers/') => self::Controller,
            str_starts_with($p, 'app/Http/Requests/') => self::Request,
            str_starts_with($p, 'app/Http/Resources/') => self::Resource,
            str_starts_with($p, 'app/Http/Middleware/') => self::Middleware,
            str_starts_with($p, 'app/Services/') => self::Service,
            str_starts_with($p, 'app/Policies/') => self::Policy,
            str_starts_with($p, 'app/Events/') => self::Event,
            str_starts_with($p, 'app/Listeners/') => self::Listener,
            str_starts_with($p, 'app/Jobs/') => self::Job,
            str_starts_with($p, 'app/Notifications/') => self::Notification,
            default => self::Other,
        };
    }
}
