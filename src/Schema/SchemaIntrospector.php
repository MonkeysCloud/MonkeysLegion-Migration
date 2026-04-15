<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Schema;

use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Migration\Dialect\SqlDialect;

use PDO;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Introspects the live database and builds TableDefinition objects
 * representing the current schema state.
 *
 * Dialect-aware: delegates driver-specific queries to the SqlDialect.
 * Includes caching so repeated calls within a single process share results.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class SchemaIntrospector
{
    /** @var array<string, TableDefinition>|null Cached full schema */
    private ?array $schemaCache = null;

    private readonly string $driver;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SqlDialect $dialect,
    ) {
        $this->driver = $this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    // ── Public API ─────────────────────────────────────────────────

    /**
     * Introspect the entire database schema.
     *
     * @return array<string, TableDefinition> table name => definition
     */
    public function introspect(): array
    {
        if ($this->schemaCache !== null) {
            return $this->schemaCache;
        }

        $pdo    = $this->db->pdo();
        $tables = $this->listTables($pdo);

        $schema = [];
        foreach ($tables as $tableName) {
            $schema[$tableName] = $this->introspectTable($tableName);
        }

        $this->schemaCache = $schema;

        return $schema;
    }

    /**
     * Introspect a single table.
     */
    public function introspectTable(string $tableName): TableDefinition
    {
        $pdo = $this->db->pdo();

        $columns     = $this->introspectColumns($pdo, $tableName);
        $indexes     = $this->introspectIndexes($pdo, $tableName);
        $foreignKeys = $this->introspectForeignKeys($pdo, $tableName);
        $primaryKey  = $this->detectPrimaryKey($columns);

        return new TableDefinition(
            name:        $tableName,
            columns:     $columns,
            primaryKey:  $primaryKey,
            indexes:     $indexes,
            foreignKeys: $foreignKeys,
        );
    }

    /**
     * Get the raw column metadata (legacy format) for backward compatibility
     * with existing MigrationGenerator::diff().
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function introspectRaw(): array
    {
        $pdo    = $this->db->pdo();
        $tables = $this->listTables($pdo);

        $schema = [];
        foreach ($tables as $tableName) {
            $schema[$tableName] = $this->introspectColumnsRaw($pdo, $tableName);
        }

        return $schema;
    }

    /**
     * Invalidate the schema cache (e.g. after DDL execution).
     */
    public function clearCache(): void
    {
        $this->schemaCache = null;
    }

    // ── Table listing ──────────────────────────────────────────────

    /**
     * List all user tables in the current database.
     *
     * @return list<string>
     */
    private function listTables(PDO $pdo): array
    {
        return match ($this->driver) {
            'pgsql'  => $this->listTablesPgsql($pdo),
            'sqlite' => $this->listTablesSqlite($pdo),
            default  => $this->listTablesMysql($pdo),
        };
    }

    /** @return list<string> */
    private function listTablesMysql(PDO $pdo): array
    {
        $stmt = $pdo->query('SHOW TABLES');

        return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    }

    /** @return list<string> */
    private function listTablesPgsql(PDO $pdo): array
    {
        $schemaStmt = $pdo->query('SELECT current_schema()');
        $pgSchema   = $schemaStmt ? ($schemaStmt->fetchColumn() ?: 'public') : 'public';

        $stmt = $pdo->prepare(
            'SELECT tablename FROM pg_tables WHERE schemaname = :schema',
        );
        $stmt->execute(['schema' => $pgSchema]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /** @return list<string> */
    private function listTablesSqlite(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'",
        );

        return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    }

    // ── Column introspection ───────────────────────────────────────

    /**
     * Introspect columns and return ColumnDefinition objects.
     *
     * @return array<string, ColumnDefinition>
     */
    private function introspectColumns(PDO $pdo, string $table): array
    {
        $rawCols = $this->introspectColumnsRaw($pdo, $table);
        $columns = [];

        foreach ($rawCols as $name => $meta) {
            $type     = $this->normalizeType((string) ($meta['Type'] ?? $meta['type'] ?? 'varchar'));
            $nullable = in_array(
                strtoupper((string) ($meta['Null'] ?? $meta['is_nullable'] ?? 'NO')),
                ['YES', 'TRUE', '1'],
                true,
            );

            $autoInc = str_contains(
                strtolower((string) ($meta['Extra'] ?? $meta['column_default'] ?? '')),
                'auto_increment',
            ) || str_contains(
                strtolower((string) ($meta['Extra'] ?? $meta['column_default'] ?? '')),
                'nextval',
            );

            $columns[$name] = new ColumnDefinition(
                name:          $name,
                type:          $type,
                nullable:      $nullable,
                autoIncrement: $autoInc,
                default:       $meta['Default'] ?? $meta['column_default'] ?? null,
            );
        }

        return $columns;
    }

    /**
     * Raw column metadata (associative arrays) for legacy compatibility.
     *
     * @return array<string, array<string, mixed>>
     */
    private function introspectColumnsRaw(PDO $pdo, string $table): array
    {
        return match ($this->driver) {
            'pgsql'  => $this->columnsPgsql($pdo, $table),
            'sqlite' => $this->columnsSqlite($pdo, $table),
            default  => $this->columnsMysql($pdo, $table),
        };
    }

    /** @return array<string, array<string, mixed>> */
    private function columnsMysql(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        if (!$stmt) {
            return [];
        }

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $name = (string) ($col['Field'] ?? '');
            if ($name !== '') {
                $result[$name] = $col;
            }
        }

        return $result;
    }

    /** @return array<string, array<string, mixed>> */
    private function columnsPgsql(PDO $pdo, string $table): array
    {
        $schemaStmt = $pdo->query('SELECT current_schema()');
        $pgSchema   = $schemaStmt ? ($schemaStmt->fetchColumn() ?: 'public') : 'public';

        $sql = <<<'SQL'
            SELECT column_name     AS "Field",
                   data_type       AS "Type",
                   is_nullable     AS "Null",
                   column_default  AS "Default",
                   CASE WHEN column_default LIKE 'nextval%%' THEN 'auto_increment' ELSE '' END AS "Extra"
              FROM information_schema.columns
             WHERE table_schema = :schema
               AND table_name  = :table
             ORDER BY ordinal_position
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['schema' => $pgSchema, 'table' => $table]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $name = (string) ($col['Field'] ?? '');
            if ($name !== '') {
                $result[$name] = $col;
            }
        }

        return $result;
    }

    /** @return array<string, array<string, mixed>> */
    private function columnsSqlite(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("PRAGMA table_info(\"{$table}\")");
        if (!$stmt) {
            return [];
        }

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $name = (string) ($col['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $result[$name] = [
                'Field'   => $name,
                'Type'    => $col['type'] ?? 'TEXT',
                'Null'    => ((int) ($col['notnull'] ?? 0)) === 0 ? 'YES' : 'NO',
                'Default' => $col['dflt_value'] ?? null,
                'Extra'   => ((int) ($col['pk'] ?? 0)) === 1 ? 'auto_increment' : '',
            ];
        }

        return $result;
    }

    // ── Index introspection ────────────────────────────────────────

    /**
     * @return list<IndexDefinition>
     */
    private function introspectIndexes(PDO $pdo, string $table): array
    {
        return match ($this->driver) {
            'pgsql'  => $this->indexesPgsql($pdo, $table),
            'sqlite' => $this->indexesSqlite($pdo, $table),
            default  => $this->indexesMysql($pdo, $table),
        };
    }

    /** @return list<IndexDefinition> */
    private function indexesMysql(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("SHOW INDEX FROM `{$table}`");
        if (!$stmt) {
            return [];
        }

        /** @var array<string, array{unique: bool, columns: list<string>}> */
        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            if ($name === 'PRIMARY') {
                continue;
            }

            $grouped[$name] ??= [
                'unique'  => ((int) ($row['Non_unique'] ?? 1)) === 0,
                'columns' => [],
            ];
            $grouped[$name]['columns'][] = (string) ($row['Column_name'] ?? '');
        }

        $indexes = [];
        foreach ($grouped as $name => $info) {
            $indexes[] = new IndexDefinition(
                name:    $name,
                columns: $info['columns'],
                unique:  $info['unique'],
            );
        }

        return $indexes;
    }

    /** @return list<IndexDefinition> */
    private function indexesPgsql(PDO $pdo, string $table): array
    {
        $sql = <<<'SQL'
            SELECT i.relname AS index_name,
                   ix.indisunique AS is_unique,
                   array_to_string(ARRAY(
                       SELECT a.attname
                         FROM unnest(ix.indkey) WITH ORDINALITY AS k(attnum, ord)
                         JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum
                        ORDER BY k.ord
                   ), ',') AS columns
              FROM pg_class t
              JOIN pg_index ix ON t.oid = ix.indrelid
              JOIN pg_class i ON i.oid = ix.indexrelid
             WHERE t.relname = :table
               AND ix.indisprimary = false
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['table' => $table]);

        $indexes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $indexes[] = new IndexDefinition(
                name:    (string) ($row['index_name'] ?? ''),
                columns: explode(',', (string) ($row['columns'] ?? '')),
                unique:  (bool) ($row['is_unique'] ?? false),
            );
        }

        return $indexes;
    }

    /** @return list<IndexDefinition> */
    private function indexesSqlite(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("PRAGMA index_list(\"{$table}\")");
        if (!$stmt) {
            return [];
        }

        $indexes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name   = (string) ($row['name'] ?? '');
            $unique = ((int) ($row['unique'] ?? 0)) === 1;

            // Get columns for this index
            $colStmt = $pdo->query("PRAGMA index_info(\"{$name}\")");
            $columns = $colStmt ? array_column($colStmt->fetchAll(PDO::FETCH_ASSOC), 'name') : [];

            $indexes[] = new IndexDefinition(
                name:    $name,
                columns: array_map('strval', $columns),
                unique:  $unique,
            );
        }

        return $indexes;
    }

    // ── FK introspection ───────────────────────────────────────────

    /**
     * @return list<ForeignKeyDefinition>
     */
    private function introspectForeignKeys(PDO $pdo, string $table): array
    {
        return match ($this->driver) {
            'pgsql'  => $this->fksPgsql($pdo, $table),
            'sqlite' => $this->fksSqlite($pdo, $table),
            default  => $this->fksMysql($pdo, $table),
        };
    }

    /** @return list<ForeignKeyDefinition> */
    private function fksMysql(PDO $pdo, string $table): array
    {
        $sql = <<<'SQL'
            SELECT kcu.CONSTRAINT_NAME     AS fk_name,
                   kcu.COLUMN_NAME         AS col,
                   kcu.REFERENCED_TABLE_NAME  AS ref_table,
                   kcu.REFERENCED_COLUMN_NAME AS ref_col,
                   rc.DELETE_RULE           AS on_delete,
                   rc.UPDATE_RULE           AS on_update
              FROM information_schema.KEY_COLUMN_USAGE kcu
              JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
               AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             WHERE kcu.TABLE_SCHEMA = DATABASE()
               AND kcu.TABLE_NAME = :table
               AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['table' => $table]);

        $fks = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fks[] = new ForeignKeyDefinition(
                name:             (string) ($row['fk_name'] ?? ''),
                column:           (string) ($row['col'] ?? ''),
                referencedTable:  (string) ($row['ref_table'] ?? ''),
                referencedColumn: (string) ($row['ref_col'] ?? ''),
                onDelete:         (string) ($row['on_delete'] ?? 'RESTRICT'),
                onUpdate:         (string) ($row['on_update'] ?? 'RESTRICT'),
            );
        }

        return $fks;
    }

    /** @return list<ForeignKeyDefinition> */
    private function fksPgsql(PDO $pdo, string $table): array
    {
        $sql = <<<'SQL'
            SELECT tc.constraint_name AS fk_name,
                   kcu.column_name    AS col,
                   ccu.table_name     AS ref_table,
                   ccu.column_name    AS ref_col,
                   rc.delete_rule     AS on_delete,
                   rc.update_rule     AS on_update
              FROM information_schema.table_constraints tc
              JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
               AND tc.table_schema = kcu.table_schema
              JOIN information_schema.constraint_column_usage ccu
                ON tc.constraint_name = ccu.constraint_name
               AND tc.table_schema = ccu.table_schema
              JOIN information_schema.referential_constraints rc
                ON tc.constraint_name = rc.constraint_name
               AND tc.table_schema = rc.constraint_schema
             WHERE tc.constraint_type = 'FOREIGN KEY'
               AND tc.table_name = :table
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['table' => $table]);

        $fks = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fks[] = new ForeignKeyDefinition(
                name:             (string) ($row['fk_name'] ?? ''),
                column:           (string) ($row['col'] ?? ''),
                referencedTable:  (string) ($row['ref_table'] ?? ''),
                referencedColumn: (string) ($row['ref_col'] ?? ''),
                onDelete:         (string) ($row['on_delete'] ?? 'RESTRICT'),
                onUpdate:         (string) ($row['on_update'] ?? 'RESTRICT'),
            );
        }

        return $fks;
    }

    /** @return list<ForeignKeyDefinition> */
    private function fksSqlite(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("PRAGMA foreign_key_list(\"{$table}\")");
        if (!$stmt) {
            return [];
        }

        $fks = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fks[] = new ForeignKeyDefinition(
                name:             ForeignKeyDefinition::generateName($table, (string) ($row['from'] ?? '')),
                column:           (string) ($row['from'] ?? ''),
                referencedTable:  (string) ($row['table'] ?? ''),
                referencedColumn: (string) ($row['to'] ?? ''),
                onDelete:         strtoupper((string) ($row['on_delete'] ?? 'RESTRICT')),
                onUpdate:         strtoupper((string) ($row['on_update'] ?? 'RESTRICT')),
            );
        }

        return $fks;
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Detect the primary key column from introspected columns.
     */
    private function detectPrimaryKey(array $columns): string
    {
        foreach ($columns as $name => $col) {
            if ($col->autoIncrement || $col->primaryKey) {
                return $name;
            }
        }

        return 'id';
    }

    /**
     * Normalize a raw SQL type string to a logical type.
     */
    private function normalizeType(string $rawType): string
    {
        $upper = strtoupper(trim($rawType));

        // Strip parenthesized length: VARCHAR(255) → VARCHAR
        $base = (string) preg_replace('/\([^)]*\)/', '', $upper);
        $base = trim($base);

        return match (true) {
            str_contains($base, 'SERIAL')    => 'int',
            str_contains($base, 'BIGINT')    => 'bigint',
            str_contains($base, 'SMALLINT')  => 'smallint',
            str_contains($base, 'TINYINT')   => 'tinyint',
            str_contains($base, 'INT')       => 'int',
            str_contains($base, 'DECIMAL'),
            str_contains($base, 'NUMERIC')   => 'decimal',
            str_contains($base, 'FLOAT'),
            str_contains($base, 'REAL'),
            str_contains($base, 'DOUBLE')    => 'float',
            $base === 'BOOLEAN'              => 'boolean',
            str_contains($base, 'TIMESTAMP') => str_contains($base, 'TZ') ? 'timestamptz' : 'timestamp',
            $base === 'DATETIME'             => 'datetime',
            $base === 'DATE'                 => 'date',
            $base === 'TIME'                 => 'time',
            $base === 'UUID'                 => 'uuid',
            str_contains($base, 'TEXT')      => 'text',
            str_contains($base, 'JSON'),
            str_contains($base, 'JSONB')     => 'json',
            str_contains($base, 'BLOB'),
            str_contains($base, 'BYTEA')     => 'binary',
            str_contains($base, 'CHAR')      => 'string',
            str_contains($base, 'VARCHAR')   => 'string',
            str_contains($base, 'ENUM')      => 'enum',
            str_contains($base, 'INET')      => 'ipaddress',
            str_contains($base, 'MACADDR')   => 'macaddress',
            default                          => 'string',
        };
    }
}
