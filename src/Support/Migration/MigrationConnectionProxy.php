<?php

namespace Aphisitworachorch\Kacher\Support\Migration;

use Closure;
use Illuminate\Database\Query\Expression;

class MigrationConnectionProxy
{
    private ?string $name;

    private MigrationSchemaBuilder $builder;

    public function __construct(?string $name, MigrationSchemaBuilder $builder)
    {
        $this->name = $name;
        $this->builder = $builder;
    }

    public function getSchemaBuilder(): MigrationSchemaBuilder
    {
        return $this->builder;
    }

    public function table(string $table): MigrationQueryProxy
    {
        return new MigrationQueryProxy();
    }

    public function query(): MigrationQueryProxy
    {
        return new MigrationQueryProxy();
    }

    public function select($query, $bindings = [], $useReadPdo = true): array
    {
        return [];
    }

    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        return null;
    }

    public function selectFromWriteConnection($query, $bindings = []): array
    {
        return [];
    }

    public function cursor($query, $bindings = [], $useReadPdo = true): \Generator
    {
        if (false) {
            yield null;
        }
    }

    public function insert($query, $bindings = []): bool
    {
        return true;
    }

    public function update($query, $bindings = []): int
    {
        return 0;
    }

    public function delete($query, $bindings = []): int
    {
        return 0;
    }

    public function statement($query, $bindings = []): bool
    {
        return true;
    }

    public function affectingStatement($query, $bindings = []): int
    {
        return 0;
    }

    public function unprepared($query): bool
    {
        return true;
    }

    public function transaction(Closure $callback)
    {
        return $callback();
    }

    public function beginTransaction(): void
    {
        // no-op
    }

    public function commit(): void
    {
        // no-op
    }

    public function rollBack($toLevel = null): void
    {
        // no-op
    }

    public function raw($value): Expression
    {
        return new Expression($value);
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function __call(string $method, array $parameters)
    {
        return null;
    }
}
