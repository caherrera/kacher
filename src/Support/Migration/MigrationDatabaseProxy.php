<?php

namespace Aphisitworachorch\Kacher\Support\Migration;

use Illuminate\Database\DatabaseManager;

class MigrationDatabaseProxy
{
    private DatabaseManager $manager;

    private MigrationSchemaBuilder $builder;

    /**
     * @var array<string, MigrationConnectionProxy>
     */
    private array $connections = [];

    public function __construct(DatabaseManager $manager, MigrationSchemaBuilder $builder)
    {
        $this->manager = $manager;
        $this->builder = $builder;
    }

    public function connection($name = null): MigrationConnectionProxy
    {
        $name = $name ?: $this->manager->getDefaultConnection();

        if (! isset($this->connections[$name])) {
            $this->connections[$name] = new MigrationConnectionProxy($name, $this->builder);
        }

        return $this->connections[$name];
    }

    public function getDefaultConnection(): string
    {
        return $this->manager->getDefaultConnection();
    }

    public function setDefaultConnection($name): void
    {
        $this->manager->setDefaultConnection($name);
    }

    public function __call(string $method, array $parameters)
    {
        return $this->manager->{$method}(...$parameters);
    }
}
