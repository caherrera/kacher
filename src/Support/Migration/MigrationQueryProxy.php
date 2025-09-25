<?php

namespace Aphisitworachorch\Kacher\Support\Migration;

class MigrationQueryProxy
{
    public function get($columns = ['*']): array
    {
        return [];
    }

    public function first($columns = ['*'])
    {
        return null;
    }

    public function value($column)
    {
        return null;
    }

    public function pluck($column, $key = null): array
    {
        return [];
    }

    public function insert(array $values): bool
    {
        return true;
    }

    public function update(array $values): int
    {
        return 0;
    }

    public function delete(): int
    {
        return 0;
    }

    public function truncate(): void
    {
        // no-op
    }

    public function exists(): bool
    {
        return false;
    }

    public function doesntExist(): bool
    {
        return true;
    }

    public function count($columns = '*'): int
    {
        return 0;
    }

    public function __call(string $method, array $arguments)
    {
        return $this;
    }
}
