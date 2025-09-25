<?php

namespace Aphisitworachorch\Kacher\Support\Migration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class MigrationSchemaCollector
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $tables = [];

    public function createTable(Blueprint $blueprint): void
    {
        $tableName = $blueprint->getTable();

        $this->tables[$tableName] = [
            'name' => $tableName,
            'comment' => $blueprint->comment ?? null,
            'columns' => [],
            'indexes' => [],
            'foreign_keys' => [],
        ];

        foreach ($blueprint->getColumns() as $column) {
            $columnData = $this->normalizeColumn($column);
            $this->tables[$tableName]['columns'][$columnData['name']] = $columnData;
        }

        foreach ($this->collectIndexes($blueprint) as $index) {
            $this->addIndex($tableName, $index);
        }

        foreach ($this->collectForeignKeys($blueprint) as $foreignKey) {
            $this->addForeignKey($tableName, $foreignKey);
        }
    }

    public function updateTable(Blueprint $blueprint): void
    {
        $tableName = $blueprint->getTable();
        $table = &$this->ensureTable($tableName);

        if ($blueprint->comment !== null) {
            $table['comment'] = $blueprint->comment;
        }

        foreach ($blueprint->getColumns() as $column) {
            $columnData = $this->normalizeColumn($column);
            $table['columns'][$columnData['name']] = $columnData;
        }

        foreach ($this->collectIndexes($blueprint) as $index) {
            $this->addIndex($tableName, $index);
        }

        foreach ($this->collectForeignKeys($blueprint) as $foreignKey) {
            $this->addForeignKey($tableName, $foreignKey);
        }

        $this->applyDropCommands($tableName, $blueprint);
    }

    public function dropTable(string $table, bool $ifExists = false): void
    {
        if (! $ifExists || isset($this->tables[$table])) {
            unset($this->tables[$table]);
        }
    }

    public function renameTable(string $from, string $to): void
    {
        if (! isset($this->tables[$from])) {
            return;
        }

        $table = $this->tables[$from];
        unset($this->tables[$from]);

        $table['name'] = $to;

        foreach ($table['indexes'] as $key => $index) {
            $index['table'] = $to;
            $table['indexes'][$key] = $index;
        }

        $this->tables[$to] = $table;
    }

    public function hasTable(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return isset($this->tables[$table]['columns'][$column]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function tables(): array
    {
        $tables = [];

        foreach ($this->tables as $table) {
            $table['columns'] = array_values($table['columns']);
            $table['indexes'] = array_values($table['indexes']);
            $table['foreign_keys'] = array_values($table['foreign_keys']);

            $tables[] = $table;
        }

        return $tables;
    }

    private function &ensureTable(string $table): array
    {
        if (! isset($this->tables[$table])) {
            $this->tables[$table] = [
                'name' => $table,
                'comment' => null,
                'columns' => [],
                'indexes' => [],
                'foreign_keys' => [],
            ];
        }

        return $this->tables[$table];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectIndexes(Blueprint $blueprint): array
    {
        $indexes = [];

        foreach ($blueprint->getCommands() as $command) {
            $name = $command->name ?? null;

            if (! in_array($name, ['primary', 'unique', 'index'], true)) {
                continue;
            }

            $indexes[] = $this->normalizeIndex($blueprint, $command->columns ?? [], $name, $command->index ?? null);
        }

        return $indexes;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectForeignKeys(Blueprint $blueprint): array
    {
        $foreignKeys = [];

        foreach ($blueprint->getCommands() as $command) {
            if (($command->name ?? null) !== 'foreign') {
                continue;
            }

            $foreignKeys[] = $this->normalizeForeignKey(
                $blueprint,
                Arr::wrap($command->columns ?? []),
                Arr::wrap($command->references ?? []),
                $command->on ?? null,
                $command->index ?? null,
                $command->onDelete ?? null,
                $command->onUpdate ?? null
            );
        }

        return $foreignKeys;
    }

    private function addIndex(string $table, array $index): void
    {
        $tableRef = &$this->ensureTable($table);
        $key = $this->indexKey($index);

        $tableRef['indexes'][$key] = $index;
    }

    private function addForeignKey(string $table, array $foreignKey): void
    {
        $tableRef = &$this->ensureTable($table);
        $key = $this->foreignKeyKey($foreignKey);

        $tableRef['foreign_keys'][$key] = $foreignKey;
    }

    private function applyDropCommands(string $table, Blueprint $blueprint): void
    {
        $tableRef = &$this->ensureTable($table);

        foreach ($blueprint->getCommands() as $command) {
            $name = $command->name ?? null;

            if ($name === 'dropColumn') {
                foreach (Arr::wrap($command->columns ?? []) as $column) {
                    unset($tableRef['columns'][$column]);
                    $this->removeColumnReferences($tableRef, $column);
                }
            }

            if (in_array($name, ['dropPrimary', 'dropUnique', 'dropIndex'], true)) {
                $this->removeIndex($tableRef, $command->index ?? null, Arr::wrap($command->columns ?? []));
            }

            if ($name === 'dropForeign') {
                $this->removeForeignKey($tableRef, $command->index ?? null, Arr::wrap($command->columns ?? []));
            }

            if ($name === 'renameColumn') {
                $from = $command->from ?? (Arr::first(array_keys($command->columns ?? [])) ?? null);
                $to = $command->to ?? (Arr::first(array_values($command->columns ?? [])) ?? null);

                if ($from !== null && $to !== null && isset($tableRef['columns'][$from])) {
                    $column = $tableRef['columns'][$from];
                    unset($tableRef['columns'][$from]);
                    $column['name'] = $to;
                    $tableRef['columns'][$to] = $column;

                    $this->renameColumnReferences($tableRef, $from, $to);
                }
            }
        }
    }

    private function removeColumnReferences(array &$table, string $column): void
    {
        foreach ($table['indexes'] as $key => $index) {
            if (in_array($column, $index['columns'], true)) {
                unset($table['indexes'][$key]);
            }
        }

        foreach ($table['foreign_keys'] as $key => $foreign) {
            if (in_array($column, $foreign['columns'], true)) {
                unset($table['foreign_keys'][$key]);
            }
        }
    }

    private function renameColumnReferences(array &$table, string $from, string $to): void
    {
        foreach ($table['indexes'] as $key => $index) {
            $columns = array_map(function ($column) use ($from, $to) {
                return $column === $from ? $to : $column;
            }, $index['columns']);

            $table['indexes'][$key]['columns'] = $columns;
        }

        foreach ($table['foreign_keys'] as $key => $foreign) {
            $columns = array_map(function ($column) use ($from, $to) {
                return $column === $from ? $to : $column;
            }, $foreign['columns']);

            $table['foreign_keys'][$key]['columns'] = $columns;
        }
    }

    private function removeIndex(array &$table, ?string $indexName, array $columns): void
    {
        if ($indexName !== null && isset($table['indexes'][$indexName])) {
            unset($table['indexes'][$indexName]);

            return;
        }

        if ($columns === []) {
            return;
        }

        foreach ($table['indexes'] as $key => $index) {
            if ($index['columns'] === $columns) {
                unset($table['indexes'][$key]);
            }
        }
    }

    private function removeForeignKey(array &$table, ?string $indexName, array $columns): void
    {
        if ($indexName !== null && isset($table['foreign_keys'][$indexName])) {
            unset($table['foreign_keys'][$indexName]);

            return;
        }

        if ($columns === []) {
            return;
        }

        foreach ($table['foreign_keys'] as $key => $foreign) {
            if ($foreign['columns'] === $columns) {
                unset($table['foreign_keys'][$key]);
            }
        }
    }

    private function normalizeColumn(ColumnDefinition $column): array
    {
        $attributes = $column->getAttributes();

        $nullable = (bool) ($attributes['nullable'] ?? false);
        $default = $this->stringifyDefault($attributes['default'] ?? null);
        $unsigned = (bool) ($attributes['unsigned'] ?? false);

        $type = $this->formatType($column, $unsigned);

        return [
            'name' => $attributes['name'],
            'type' => $type,
            'comment' => $attributes['comment'] ?? null,
            'default' => $default,
            'nullable' => $nullable,
            'length' => $attributes['length'] ?? ($attributes['total'] ?? null),
            'precision' => $attributes['precision'] ?? $attributes['total'] ?? null,
            'scale' => $attributes['scale'] ?? $attributes['places'] ?? null,
            'unsigned' => $unsigned,
            'auto_increment' => (bool) ($attributes['autoIncrement'] ?? false),
        ];
    }

    private function formatType(ColumnDefinition $column, bool $unsigned): string
    {
        $attributes = $column->getAttributes();
        $type = $attributes['type'] ?? 'string';

        $typeMap = [
            'bigIncrements' => 'bigint',
            'increments' => 'int',
            'mediumIncrements' => 'mediumint',
            'smallIncrements' => 'smallint',
            'tinyIncrements' => 'tinyint',
            'id' => 'bigint',
            'foreignId' => 'bigint',
            'foreignUuid' => 'uuid',
            'foreignUlid' => 'ulid',
            'unsignedBigInteger' => 'bigint',
            'unsignedInteger' => 'int',
            'unsignedMediumInteger' => 'mediumint',
            'unsignedSmallInteger' => 'smallint',
            'unsignedTinyInteger' => 'tinyint',
        ];

        $baseType = $typeMap[$type] ?? $type;

        if (isset($attributes['total'], $attributes['places'])) {
            $baseType .= sprintf('(%s,%s)', $attributes['total'], $attributes['places']);
        } elseif (isset($attributes['precision'], $attributes['scale'])) {
            $baseType .= sprintf('(%s,%s)', $attributes['precision'], $attributes['scale']);
        } elseif (isset($attributes['length'])) {
            $baseType .= sprintf('(%s)', $attributes['length']);
        }

        if ($unsigned && ! Str::contains($baseType, 'unsigned')) {
            $baseType .= ' unsigned';
        }

        return $baseType;
    }

    private function normalizeIndex(Blueprint $blueprint, $columns, string $type, ?string $name): array
    {
        $columns = array_values(array_map('strval', Arr::wrap($columns)));
        $indexName = $name ?? $this->defaultIndexName($blueprint->getTable(), $columns, $type);

        return [
            'name' => $indexName,
            'columns' => $columns,
            'unique' => in_array($type, ['primary', 'unique'], true),
            'primary' => $type === 'primary',
            'table' => $blueprint->getTable(),
        ];
    }

    private function normalizeForeignKey(Blueprint $blueprint, array $columns, array $references, ?string $on, ?string $name, ?string $onDelete, ?string $onUpdate): array
    {
        $columns = array_values(array_map('strval', $columns));
        $references = array_values(array_map('strval', $references));
        $indexName = $name ?? $this->defaultForeignKeyName($blueprint->getTable(), $columns);

        return [
            'name' => $indexName,
            'columns' => $columns,
            'foreign_table' => $on,
            'foreign_columns' => $references,
            'on_update' => $onUpdate,
            'on_delete' => $onDelete,
        ];
    }

    private function indexKey(array $index): string
    {
        if (! empty($index['name'])) {
            return $index['name'];
        }

        $prefix = $index['primary'] ? 'primary' : ($index['unique'] ? 'unique' : 'index');

        return $prefix.'::'.implode(',', $index['columns']);
    }

    private function foreignKeyKey(array $foreignKey): string
    {
        if (! empty($foreignKey['name'])) {
            return $foreignKey['name'];
        }

        return 'foreign::'.implode(',', $foreignKey['columns']).'->'.$foreignKey['foreign_table'];
    }

    private function defaultIndexName(string $table, array $columns, string $type): string
    {
        $normalizedType = $type === 'primary' ? 'primary' : ($type === 'unique' ? 'unique' : 'index');

        return strtolower($table.'_'.implode('_', $columns).'_'.$normalizedType);
    }

    private function defaultForeignKeyName(string $table, array $columns): string
    {
        return strtolower($table.'_'.implode('_', $columns).'_foreign');
    }

    private function stringifyDefault($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }
}
