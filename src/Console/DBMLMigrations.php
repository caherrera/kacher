<?php

namespace Aphisitworachorch\Kacher\Console;

use Aphisitworachorch\Kacher\Controller\DBMLController;
use Aphisitworachorch\Kacher\Support\Migration\MigrationSchemaBuilder;
use Aphisitworachorch\Kacher\Support\Migration\MigrationSchemaCollector;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DBMLMigrations extends Command
{
    protected $signature = 'dbml:migrations {--dbdocs} {--path=*} {--database=} {--custom}';

    protected $description = 'Parse migration files and export the resulting schema to DBML.';

    public function handle(): int
    {
        $customTypes = $this->customTypes();
        $connection = $this->option('database') ?: config('database.default');
        $driver = $this->determineDriver($connection);
        $databaseName = $this->determineDatabaseName($connection);

        $collector = new MigrationSchemaCollector();
        $schemaBuilder = new MigrationSchemaBuilder($collector);
        $originalSchema = Schema::getFacadeRoot();

        Schema::swap($schemaBuilder);

        $result = self::SUCCESS;

        try {
            $this->processMigrations($collector);

            $controller = new DBMLController(
                $customTypes,
                null,
                $connection,
                $collector->tables(),
                $databaseName,
                $driver
            );

            $dbml = $controller->parseToDBML();
            $filePath = $this->writeDbmlFile($dbml, $databaseName);

            $this->info('Created ! File Path : '.$filePath);

            if ($this->option('dbdocs') !== null) {
                $password = Str::random(8);
                $this->warn('Please Install dbdocs (npm install -g dbdocs) before run command');
                $this->info("Now you can run with command : dbdocs build $filePath --project=$databaseName --password=$password");
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $result = self::FAILURE;
        } finally {
            Schema::swap($originalSchema);
        }

        return $result;
    }

    protected function customTypes(): ?array
    {
        if ($this->option('custom') === null) {
            return null;
        }

        $path = storage_path('app/custom_type.json');

        if (! file_exists($path)) {
            $this->warn('Could not load custom type mappings from storage/app/custom_type.json');

            return null;
        }

        return json_decode((string) file_get_contents($path), true) ?: null;
    }

    protected function determineDriver(?string $connection): string
    {
        $connection = $connection ?: config('database.default');
        $config = $connection ? config("database.connections.$connection") : [];

        return $config['driver'] ?? env('DB_CONNECTION', 'mysql');
    }

    protected function determineDatabaseName(?string $connection): string
    {
        $connection = $connection ?: config('database.default');
        $config = $connection ? config("database.connections.$connection") : [];
        $database = $config['database'] ?? env('DB_DATABASE') ?? $connection ?? 'database';

        if (is_string($database) && str_contains($database, DIRECTORY_SEPARATOR)) {
            $database = pathinfo($database, PATHINFO_FILENAME);
        }

        return $database ?: 'database';
    }

    protected function processMigrations(MigrationSchemaCollector $collector): void
    {
        $paths = $this->resolveMigrationPaths();
        $files = $this->gatherMigrationFiles($paths);

        foreach ($files as $file) {
            $migration = $this->resolveMigration($file);

            if (! $migration instanceof Migration) {
                continue;
            }

            if (method_exists($migration, 'up')) {
                $migration->up();
            }
        }
    }

    protected function resolveMigrationPaths(): array
    {
        $paths = $this->option('path');

        if (! is_array($paths) || $paths === []) {
            $paths = [database_path('migrations')];
        }

        $resolved = [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $absolute = $this->isAbsolutePath($path)
                ? $path
                : $this->laravel->basePath($path);

            if (is_dir($absolute)) {
                $resolved[] = $absolute;
            }
        }

        return array_values(array_unique($resolved));
    }

    protected function gatherMigrationFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            foreach (glob(rtrim($path, DIRECTORY_SEPARATOR).'/*.php') ?: [] as $file) {
                $files[$this->migrationName($file)] = $file;
            }
        }

        ksort($files);

        return array_values($files);
    }

    protected function resolveMigration(string $file): ?Migration
    {
        $migration = require $file;

        if ($migration instanceof Migration) {
            return $migration;
        }

        $class = $this->migrationClass($file);

        if ($class !== null && class_exists($class)) {
            return app()->make($class);
        }

        return null;
    }

    protected function migrationClass(string $path): ?string
    {
        $file = basename($path, '.php');
        $name = preg_replace('/^[0-9_]+/', '', $file);

        if (! $name) {
            return null;
        }

        return Str::studly($name);
    }

    protected function migrationName(string $path): string
    {
        return basename($path, '.php');
    }

    protected function isAbsolutePath(string $path): bool
    {
        if (Str::startsWith($path, ['/', '\\'])) {
            return true;
        }

        return strlen($path) > 1 && $path[1] === ':';
    }

    protected function writeDbmlFile(string $content, string $database): string
    {
        $safeDatabase = Str::slug($database ?: 'database', '_');
        $rand = Str::random(8);
        $path = "dbml/dbml_{$safeDatabase}_{$rand}.txt";

        Storage::put($path, $content);

        return Storage::path($path);
    }
}
