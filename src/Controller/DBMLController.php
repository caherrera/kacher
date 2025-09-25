<?php

namespace Aphisitworachorch\Kacher\Controller;

use Aphisitworachorch\Kacher\Support\SchemaInspector;
use Aphisitworachorch\Kacher\Traits\DBMLSyntaxTraits;
use App\Http\Controllers\Controller;
use Exception;

class DBMLController extends Controller
{
    use DBMLSyntaxTraits;

    private ?SchemaInspector $schemaInspector;

    private ?string $connection;

    private ?array $preloadedTables;

    private ?string $databaseName;

    private ?string $driver;

    public function __construct($custom_type = null, ?SchemaInspector $schemaInspector = null, ?string $connection = null, ?array $tables = null, ?string $databaseName = null, ?string $driver = null)
    {
        $this->preloadedTables = $tables;
        $this->databaseName = $databaseName;
        $this->driver = $driver;

        if ($tables === null) {
            $this->schemaInspector = $schemaInspector ?? new SchemaInspector($connection);
            $this->connection = $this->schemaInspector->connectionName() ?? $connection;
        } else {
            $this->schemaInspector = $schemaInspector;
            $this->connection = $schemaInspector?->connectionName() ?? $connection;
        }

        /*if ($custom_type != null){
            foreach($custom_type as $ct => $key) {
                DB::connection()->getDoctrineSchemaManager()->getDatabasePlatform()->registerDoctrineTypeMapping($ct, $key);
            }
        }*/
    }

    /**
     * Prepare columns for the requested output format.
     */
    private function formatColumns(array $table, string $type): array
    {
        $primaryColumns = $this->collectPrimaryColumns($table['indexes']);
        $uniqueColumns = $this->collectUniqueColumns($table['indexes']);

        $columns = [];
        foreach ($table['columns'] as $column) {
            if ($type === 'artisan') {
                $columns[] = "name : {$column['name']}\n" . "type : {$column['type']}\n";
                continue;
            }

            $special = [];
            if (in_array($column['name'], $primaryColumns, true)) {
                $special[] = 'pk';
            }
            if (in_array($column['name'], $uniqueColumns, true)) {
                $special[] = 'unique';
            }

            $length = $column['length'];
            if ($length === null) {
                if (preg_match('/.+\(([0-9]+)\)/', $column['type'], $matches)) {
                    $length = $matches[1];
                }
            }

            $columns[] = [
                'name' => $column['name'],
                'type' => $column['type'],
                'special' => $special,
                'note' => $column['comment'],
                'default_value' => $column['default'],
                'is_nullable' => $column['nullable'] ? 'yes' : 'no',
                'length' => $length !== null ? (string) $length : '',
            ];
        }

        return $columns;
    }

    /**
     * Prepare foreign keys for the requested output format.
     */
    private function formatForeignKeys(array $table, string $type): array
    {
        $foreignKeys = [];

        foreach ($table['foreign_keys'] as $foreignKey) {
            $fromColumns = implode(' | ', $foreignKey['columns']);
            $toColumns = implode(' | ', $foreignKey['foreign_columns']);

            if ($type === 'artisan') {
                $foreignKeys[] = "[{$table['name']}][{$fromColumns}] -> [{$toColumns}] of [{$foreignKey['foreign_table']}]";
                continue;
            }

            $foreignKeys[] = [
                'from' => $table['name'],
                'name' => $fromColumns,
                'to' => $toColumns,
                'table' => $foreignKey['foreign_table'],
            ];
        }

        return $foreignKeys;
    }

    /**
     * Prepare indexes for the requested output format.
     */
    private function formatIndexes(array $table, string $type): array
    {
        $indexes = [];

        foreach ($table['indexes'] as $index) {
            $columns = implode(' | ', $index['columns']);
            $unique = $index['unique'] ? 'yes' : 'no';
            $primary = $index['primary'] ? 'yes' : 'no';

            if ($type === 'artisan') {
                $indexes[] = "name : {$index['name']}\n" .
                    "columns : {$columns}\n" .
                    "unique : {$unique}\n" .
                    "primary : {$primary}\n";
                continue;
            }

            $indexes[] = [
                'name' => $index['name'],
                'columns' => $index['columns'],
                'unique' => $unique,
                'primary' => $primary,
                'table' => $table['name'],
            ];
        }

        return $indexes;
    }

    /**
     * Gather the columns that belong to a primary key.
     */
    private function collectPrimaryColumns(array $indexes): array
    {
        $columns = [];
        foreach ($indexes as $index) {
            if (! empty($index['primary'])) {
                $columns = array_merge($columns, $index['columns']);
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * Gather the columns that belong to single-column unique indexes.
     */
    private function collectUniqueColumns(array $indexes): array
    {
        $columns = [];
        foreach ($indexes as $index) {
            if (! empty($index['unique']) && count($index['columns']) === 1) {
                $columns[] = $index['columns'][0];
            }
        }

        return array_values(array_unique($columns));
    }

    public function getDatabaseTable($type)
    {
        $tables = $this->preloadedTables ?? ($this->schemaInspector?->tables() ?? []);
        $data = [];

        foreach ($tables as $table) {
            if ($type === 'artisan') {
                $data[] = [
                    'table_name' => $table['name'],
                    'columns' => implode("\n", $this->formatColumns($table, 'artisan')),
                    'foreign_key' => implode("\n", $this->formatForeignKeys($table, 'artisan')),
                    'indexes' => implode("\n", $this->formatIndexes($table, 'artisan')),
                    'comment' => $table['comment'],
                ];
                continue;
            }

            if ($type === 'array') {
                $data[] = [
                    'table_name' => $table['name'],
                    'columns' => $this->formatColumns($table, 'array'),
                    'foreign_key' => $this->formatForeignKeys($table, 'array'),
                    'indexes' => $this->formatIndexes($table, 'array'),
                    'comment' => $table['comment'],
                ];
            }
        }

        return $data;
    }

    public function getDatabasePlatform()
    {
        $connection = $this->connection ?? config('database.default');
        $config = $connection ? config("database.connections.$connection") : [];

        $driver = $this->driver
            ?? $config['driver']
            ?? env('DB_CONNECTION');

        $database = $this->databaseName
            ?? $config['database']
            ?? env('DB_DATABASE');

        if (is_string($database) && str_contains($database, DIRECTORY_SEPARATOR)) {
            $database = pathinfo($database, PATHINFO_FILENAME);
        }

        return $this->projectName($database ?? 'database', $driver ?? 'mysql');
    }

    public function parseToDBML()
    {
        try {
            $table = $this->getDatabaseTable('array');
            $syntax = $this->getDatabasePlatform();

            foreach ($table as $info) {
                if (! $info['table_name']) {
                    continue;
                }

                $syntax .= $this->table($info['table_name']) . $this->start();

                foreach ($info['columns'] as $col) {
                    $syntax .= $this->column(
                        $col['name'],
                        $col['type'],
                        $col['special'],
                        $col['note'],
                        $col['is_nullable'],
                        $col['default_value'] ?? null,
                        ''
                    );
                }

                if ($info['comment']) {
                    $syntax .= "\n\tNote: '" . $info['comment'] . "'\n";
                }

                if ($info['indexes']) {
                    $syntax .= $this->index() . $this->start();
                    foreach ($info['indexes'] as $index) {
                        $type = '';
                        if ($index['primary'] === 'yes') {
                            $type = 'pk';
                        } elseif ($index['unique'] === 'yes') {
                            $type = 'unique';
                        }
                        $syntax .= $this->indexesKey($index['columns'], $type);
                    }
                    $syntax .= "\t" . $this->end();
                }

                $syntax .= $this->end();

                if ($info['foreign_key']) {
                    foreach ($info['foreign_key'] as $fk) {
                        $syntax .= $this->foreignKey($fk['from'], $fk['name'], $fk['table'], $fk['to']) . "\n";
                    }
                }
            }

            return $syntax . "\n";
        } catch (Exception $e) {
            print_r($e->getMessage());
        }
    }
}
