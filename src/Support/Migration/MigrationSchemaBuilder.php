<?php

namespace Aphisitworachorch\Kacher\Support\Migration;

use Closure;
use Illuminate\Database\Schema\Blueprint;

class MigrationSchemaBuilder
{
    private MigrationSchemaCollector $collector;

    /**
     * @var callable|null
     */
    private $blueprintResolver = null;

    public function __construct(MigrationSchemaCollector $collector)
    {
        $this->collector = $collector;
    }

    public function connection($name): self
    {
        return $this;
    }

    public function setConnection($name): self
    {
        return $this;
    }

    public function getConnection(): ?object
    {
        return null;
    }

    public function create(string $table, Closure $callback): void
    {
        $this->collector->createTable($this->buildBlueprint($table, $callback));
    }

    public function table(string $table, Closure $callback): void
    {
        $this->collector->updateTable($this->buildBlueprint($table, $callback));
    }

    public function drop(string $table): void
    {
        $this->collector->dropTable($table);
    }

    public function dropIfExists(string $table): void
    {
        $this->collector->dropTable($table, true);
    }

    public function rename(string $from, string $to): void
    {
        $this->collector->renameTable($from, $to);
    }

    public function hasTable(string $table): bool
    {
        return $this->collector->hasTable($table);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return $this->collector->hasColumn($table, $column);
    }

    public function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! $this->collector->hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    public function disableForeignKeyConstraints(): void
    {
        // no-op for migration parsing
    }

    public function enableForeignKeyConstraints(): void
    {
        // no-op for migration parsing
    }

    public function defaultStringLength($length): void
    {
        // no-op for migration parsing
    }

    public function withoutForeignKeyConstraints(Closure $callback)
    {
        return $callback();
    }

    public function blueprintResolver(callable $resolver): void
    {
        $this->blueprintResolver = $resolver;
    }

    private function buildBlueprint(string $table, Closure $callback): Blueprint
    {
        $blueprint = $this->resolveBlueprint($table);
        $callback($blueprint);

        return $blueprint;
    }

    private function resolveBlueprint(string $table): Blueprint
    {
        if ($this->blueprintResolver !== null) {
            return call_user_func($this->blueprintResolver, $table, null);
        }

        return new Blueprint($table);
    }

    public function __call(string $method, array $parameters)
    {
        return null;
    }
}
