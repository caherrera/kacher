<?php

namespace Aphisitworachorch\Kacher\Console;

use Aphisitworachorch\Kacher\Controller\DBMLController;
use Aphisitworachorch\Kacher\Support\SchemaInspector;
use Doctrine\DBAL\Exception;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DBMLMigrations extends Command
{
    protected $signature = 'dbml:migrations {--dbdocs} {--path=*} {--database=} {--custom}';

    protected $description = 'Run migrations on a scratch database and export them to DBML.';

    /**
     * @throws Exception
     */
    public function handle(): int
    {
        [$connection, $databasePath, $cleanupConnection] = $this->prepareConnection($this->option('database'));

        $customTypes = $this->customTypes();
        $originalDefault = config('database.default');

        config(['database.default' => $connection]);
        DB::setDefaultConnection($connection);

        $migrator = app(Migrator::class);
        $migrator->setConnection($connection);
        $migrator->setOutput($this->output);
        $migrator->getRepository()->setSource($connection);

        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        $paths = $this->resolveMigrationPaths();

        $result = self::SUCCESS;

        try {
            $migrator->run($paths, ['step' => false]);

            $controller = new DBMLController($customTypes, new SchemaInspector($connection), $connection);
            $dbml = $controller->parseToDBML();

            $databaseName = $this->connectionDatabaseName($connection);
            $fileName = $this->writeDbmlFile($dbml, $databaseName);
            $this->info('Created ! File Path : '.$fileName);

            if ($this->option('dbdocs') !== null) {
                $password = Str::random(8);
                $this->warn('Please Install dbdocs (npm install -g dbdocs) before run command');
                $this->info("Now you can run with command : dbdocs build $fileName --project=$databaseName --password=$password");
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $result = self::FAILURE;
        } finally {
            config(['database.default' => $originalDefault]);
            DB::setDefaultConnection($originalDefault ?? config('database.default'));
            $this->cleanupConnection($connection, $databasePath, $cleanupConnection);
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

    protected function resolveMigrationPaths(): array
    {
        $paths = $this->option('path');

        if (! is_array($paths) || count($paths) === 0) {
            $paths = [database_path('migrations')];
        }

        $resolved = [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $resolved[] = $this->isAbsolutePath($path)
                ? $path
                : $this->laravel->basePath($path);
        }

        return array_values(array_unique($resolved));
    }

    protected function isAbsolutePath(string $path): bool
    {
        if (Str::startsWith($path, ['/', '\\'])) {
            return true;
        }

        return strlen($path) > 1 && $path[1] === ':';
    }

    protected function prepareConnection(?string $requested): array
    {
        $connection = $requested ?: 'kacher_migrations';
        $cleanup = false;
        $databasePath = null;

        if (! config("database.connections.$connection")) {
            $databasePath = storage_path('app/'.$connection.'.sqlite');
            $this->initializeSqliteDatabase($databasePath);

            config(["database.connections.$connection" => [
                'driver' => 'sqlite',
                'database' => $databasePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);

            $cleanup = true;
        } else {
            $existing = config("database.connections.$connection");
            if (($existing['driver'] ?? null) === 'sqlite') {
                $databasePath = $existing['database'] ?? null;
                if ($databasePath && $databasePath !== ':memory:') {
                    $this->initializeSqliteDatabase($databasePath);
                }
            }
        }

        return [$connection, $databasePath, $cleanup];
    }

    protected function initializeSqliteDatabase(string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (file_exists($path)) {
            unlink($path);
        }

        touch($path);
    }

    protected function connectionDatabaseName(string $connection): string
    {
        $config = config("database.connections.$connection", []);
        $database = $config['database'] ?? $connection;

        if (is_string($database) && str_contains($database, DIRECTORY_SEPARATOR)) {
            $database = pathinfo($database, PATHINFO_FILENAME);
        }

        return $database ?: $connection;
    }

    protected function writeDbmlFile(string $content, string $database): string
    {
        $safeDatabase = Str::slug($database ?: 'database', '_');
        $rand = Str::random(8);
        $path = "dbml/dbml_{$safeDatabase}_{$rand}.txt";

        Storage::put($path, $content);

        return Storage::path($path);
    }

    protected function cleanupConnection(string $connection, ?string $databasePath, bool $cleanupConnection): void
    {
        DB::disconnect($connection);
        DB::purge($connection);

        if ($cleanupConnection) {
            if ($databasePath && file_exists($databasePath)) {
                unlink($databasePath);
            }

            $configRepository = app('config');

            if (method_exists($configRepository, 'offsetUnset')) {
                $configRepository->offsetUnset("database.connections.$connection");
            }
        }
    }
}
