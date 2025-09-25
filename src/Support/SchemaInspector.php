<?php

namespace Aphisitworachorch\Kacher\Support;

use Doctrine\DBAL\Schema\Column as DoctrineColumn;
use Doctrine\DBAL\Schema\ForeignKeyConstraint as DoctrineForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index as DoctrineIndex;
use Doctrine\DBAL\Schema\Table as DoctrineTable;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SchemaInspector
{
    private ?string $connection;

    private ?Connection $connectionInstance = null;

    private ?Builder $builderInstance = null;

    private ?string $driver = null;

    public function __construct(?string $connection = null)
    {
        $this->connection = $connection;
    }

    public function connectionName(): ?string
    {
        return $this->connection;
    }

    /**
     * Retrieve the connection instance.
     */
    protected function connection(): Connection
    {
        if ($this->connectionInstance === null) {
            $this->connectionInstance = DB::connection($this->connection);
        }

        return $this->connectionInstance;
    }

    /**
     * Retrieve the schema builder for the configured connection.
     */
    protected function builder(): Builder
    {
        if ($this->builderInstance === null) {
            $this->builderInstance = $this->connection()->getSchemaBuilder();
        }

        return $this->builderInstance;
    }

    /**
     * Inspect the database schema and return a normalized description of the tables.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tables(): array
    {
        return $this->hasSchemaInspectionApi()
            ? $this->inspectUsingSchemaBuilder()
            : $this->inspectUsingDoctrine();
    }

    /**
     * Determine if the current Laravel version exposes the schema inspection API.
     */
    protected function hasSchemaInspectionApi(): bool
    {
        $builder = $this->builder();

        return method_exists($builder, 'getTables')
            && method_exists($builder, 'getColumns')
            && method_exists($builder, 'getIndexes')
            && method_exists($builder, 'getForeignKeys');
    }

    /**
     * Inspect schema information using Laravel's schema builder API (Laravel 10+).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function inspectUsingSchemaBuilder(): array
    {
        $builder = $this->builder();
        $prefix = $this->connection()->getTablePrefix();

        $tables = [];

        foreach ($builder->getTables() as $table) {
            $tableName = $table['name'];
            $unprefixed = $this->stripPrefix($tableName, $prefix);

            $rawForeignKeys = $builder->getForeignKeys($unprefixed);

            if (empty($rawForeignKeys) && $this->connectionDriver() === 'sqlite') {
                $rawForeignKeys = $this->sqliteForeignKeys($unprefixed);
            }

            $tables[] = [
                'name' => $tableName,
                'comment' => $table['comment'] ?? null,
                'columns' => $this->normalizeColumns($builder->getColumns($unprefixed)),
                'indexes' => $this->normalizeIndexes($builder->getIndexes($unprefixed)),
                'foreign_keys' => $this->normalizeForeignKeys($rawForeignKeys),
            ];
        }

        return $tables;
    }

    /**
     * Inspect schema information using Doctrine DBAL (Laravel 9 fallback).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function inspectUsingDoctrine(): array
    {
        if (! class_exists(DoctrineTable::class)) {
            throw new RuntimeException('Schema inspection requires doctrine/dbal for Laravel 9 support.');
        }

        $connection = $this->connection();

        if (! method_exists($connection, 'getDoctrineSchemaManager')) {
            throw new RuntimeException('The database connection does not expose a Doctrine schema manager.');
        }

        $schemaManager = $connection->getDoctrineSchemaManager();
        $tables = [];

        foreach ($schemaManager->listTables() as $table) {
            $tables[] = [
                'name' => $table->getName(),
                'comment' => $table->getComment() ?: null,
                'columns' => $this->normalizeDoctrineColumns($table),
                'indexes' => $this->normalizeDoctrineIndexes($table),
                'foreign_keys' => $this->normalizeDoctrineForeignKeys($table),
            ];
        }

        return $tables;
    }

    /**
     * Normalize columns returned by Laravel's schema builder into a consistent shape.
     *
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeColumns(array $columns): array
    {
        return array_map(function (array $column) {
            $length = Arr::first([
                $column['length'] ?? null,
                $column['character_maximum_length'] ?? null,
            ], fn ($value) => $value !== null);

            $precision = Arr::first([
                $column['precision'] ?? null,
                $column['numeric_precision'] ?? null,
            ], fn ($value) => $value !== null);

            $scale = Arr::first([
                $column['scale'] ?? null,
                $column['numeric_scale'] ?? null,
            ], fn ($value) => $value !== null);

            $rawType = $column['type'] ?? null;
            $typeName = $column['type_name'] ?? $column['data_type'] ?? null;
            $unsigned = (bool) ($column['unsigned'] ?? false);

            return [
                'name' => $column['name'],
                'type' => $this->buildTypeDefinition($rawType, $typeName, $length, $precision, $scale, $unsigned),
                'type_name' => $typeName ?? $rawType,
                'comment' => $column['comment'] ?? null,
                'default' => $this->stringifyDefault($column['default'] ?? null),
                'nullable' => (bool) ($column['nullable'] ?? false),
                'length' => $length,
                'precision' => $precision,
                'scale' => $scale,
                'unsigned' => $unsigned,
            ];
        }, $columns);
    }

    /**
     * Normalize indexes returned by Laravel's schema builder.
     *
     * @param  array<int, array<string, mixed>>  $indexes
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeIndexes(array $indexes): array
    {
        return array_map(function (array $index) {
            $columns = $index['columns'] ?? [];
            if (is_string($columns)) {
                $columns = explode(',', $columns);
            }

            $columns = array_values(array_filter(array_map('trim', $columns), fn ($value) => $value !== ''));

            return [
                'name' => $index['name'],
                'columns' => $columns,
                'unique' => (bool) ($index['unique'] ?? false),
                'primary' => (bool) ($index['primary'] ?? false),
                'type' => $index['type'] ?? null,
            ];
        }, $indexes);
    }

    /**
     * Normalize foreign keys returned by Laravel's schema builder.
     *
     * @param  array<int, array<string, mixed>>  $foreignKeys
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeForeignKeys(array $foreignKeys): array
    {
        return array_map(function (array $foreignKey) {
            $columns = $foreignKey['columns'] ?? $foreignKey['column'] ?? [];
            $foreignColumns = $foreignKey['foreign_columns']
                ?? $foreignKey['references']
                ?? $foreignKey['referenced_columns']
                ?? [];

            if (is_string($columns)) {
                $columns = explode(',', $columns);
            }

            if (is_string($foreignColumns)) {
                $foreignColumns = explode(',', $foreignColumns);
            }

            $foreignTable = $foreignKey['foreign_table']
                ?? $foreignKey['on']
                ?? $foreignKey['referenced_table']
                ?? $foreignKey['referenced_table_name']
                ?? null;

            return [
                'name' => $foreignKey['name'] ?? null,
                'columns' => array_values(array_filter(array_map('trim', $columns))),
                'foreign_table' => $foreignTable,
                'foreign_columns' => array_values(array_filter(array_map('trim', $foreignColumns))),
                'on_update' => $foreignKey['on_update'] ?? $foreignKey['onUpdate'] ?? null,
                'on_delete' => $foreignKey['on_delete'] ?? $foreignKey['onDelete'] ?? null,
            ];
        }, $foreignKeys);
    }

    protected function sqliteForeignKeys(string $table): array
    {
        $connection = $this->connection();
        $escapedName = str_replace("'", "''", $table);
        $results = $connection->select("PRAGMA foreign_key_list('{$escapedName}')");

        $grouped = [];

        foreach ($results as $result) {
            $id = $result->id ?? spl_object_id($result);

            if (! isset($grouped[$id])) {
                $grouped[$id] = [
                    'name' => isset($result->id) ? sprintf('fk_%s_%s', $table, $result->id) : null,
                    'columns' => [],
                    'foreign_table' => $result->table ?? null,
                    'foreign_columns' => [],
                    'on_update' => $result->on_update ?? null,
                    'on_delete' => $result->on_delete ?? null,
                ];
            }

            $grouped[$id]['columns'][] = $result->from ?? null;
            $grouped[$id]['foreign_columns'][] = $result->to ?? null;
        }

        return array_map(function (array $foreignKey) {
            $foreignKey['columns'] = array_values(array_filter($foreignKey['columns'], fn ($value) => $value !== null && $value !== ''));
            $foreignKey['foreign_columns'] = array_values(array_filter($foreignKey['foreign_columns'], fn ($value) => $value !== null && $value !== ''));

            return $foreignKey;
        }, array_values($grouped));
    }

    protected function connectionDriver(): ?string
    {
        if ($this->driver === null) {
            $this->driver = $this->connection()->getDriverName();
        }

        return $this->driver;
    }

    /**
     * Normalize columns returned by Doctrine DBAL.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeDoctrineColumns(DoctrineTable $table): array
    {
        return array_map(function (DoctrineColumn $column) {
            $length = $column->getLength();
            $precision = $column->getPrecision();
            $scale = $column->getScale();
            $typeName = $column->getType()->getName();
            $unsigned = method_exists($column, 'getUnsigned') ? (bool) $column->getUnsigned() : false;

            return [
                'name' => $column->getName(),
                'type' => $this->buildTypeDefinition(null, $typeName, $length, $precision, $scale, $unsigned),
                'type_name' => $typeName,
                'comment' => $column->getComment(),
                'default' => $this->stringifyDefault($column->getDefault()),
                'nullable' => ! $column->getNotnull(),
                'length' => $length,
                'precision' => $precision,
                'scale' => $scale,
                'unsigned' => $unsigned,
            ];
        }, $table->getColumns());
    }

    /**
     * Normalize indexes returned by Doctrine DBAL.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeDoctrineIndexes(DoctrineTable $table): array
    {
        return array_map(function (DoctrineIndex $index) {
            return [
                'name' => $index->getName(),
                'columns' => $index->getColumns(),
                'unique' => $index->isUnique(),
                'primary' => $index->isPrimary(),
                'type' => $index->isPrimary() ? 'primary' : ($index->isUnique() ? 'unique' : null),
            ];
        }, $table->getIndexes());
    }

    /**
     * Normalize foreign keys returned by Doctrine DBAL.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeDoctrineForeignKeys(DoctrineTable $table): array
    {
        return array_map(function (DoctrineForeignKeyConstraint $foreignKey) {
            return [
                'name' => $foreignKey->getName(),
                'columns' => $foreignKey->getLocalColumns(),
                'foreign_table' => $foreignKey->getForeignTableName(),
                'foreign_columns' => $foreignKey->getForeignColumns(),
                'on_update' => $foreignKey->onUpdate() ? strtolower($foreignKey->onUpdate()) : null,
                'on_delete' => $foreignKey->onDelete() ? strtolower($foreignKey->onDelete()) : null,
            ];
        }, $table->getForeignKeys());
    }

    /**
     * Build a type definition string that resembles the output from Laravel's schema builder API.
     */
    protected function buildTypeDefinition(?string $rawType, ?string $typeName, $length, $precision, $scale, bool $unsigned): string
    {
        $rawType = $rawType !== null ? (string) $rawType : null;
        $typeName = $typeName !== null ? (string) $typeName : null;

        $definition = $rawType;

        if ($definition === null || $definition === '') {
            $definition = $typeName ?: 'string';

            if ($precision !== null && $scale !== null) {
                $definition .= sprintf('(%s,%s)', $precision, $scale);
            } elseif ($length !== null) {
                $definition .= sprintf('(%s)', $length);
            }
        }

        if ($unsigned && ! str_contains($definition, 'unsigned')) {
            $definition .= ' unsigned';
        }

        return $definition;
    }

    /**
     * Convert default values to strings when possible.
     */
    protected function stringifyDefault($default): ?string
    {
        if ($default === null) {
            return null;
        }

        if (is_bool($default)) {
            return $default ? '1' : '0';
        }

        if (is_scalar($default)) {
            return (string) $default;
        }

        if (is_object($default) && method_exists($default, '__toString')) {
            return (string) $default;
        }

        return json_encode($default);
    }

    /**
     * Remove the configured table prefix from a table name when present.
     */
    protected function stripPrefix(string $table, string $prefix): string
    {
        if ($prefix !== '' && str_starts_with($table, $prefix)) {
            return substr($table, strlen($prefix));
        }

        return $table;
    }
}
