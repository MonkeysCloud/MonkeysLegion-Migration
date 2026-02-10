<?php

declare(strict_types=1);

namespace MonkeysLegion\Migration\Dialect;

/**
 * PostgreSQL-specific SQL generation.
 *
 * Key differences from MySQL:
 *  - double-quote identifiers instead of backticks
 *  - SERIAL / BIGSERIAL instead of AUTO_INCREMENT
 *  - BOOLEAN instead of TINYINT(1)
 *  - TEXT instead of MEDIUMTEXT / LONGTEXT
 *  - TIMESTAMP instead of DATETIME
 *  - BYTEA instead of BLOB
 *  - INTEGER instead of YEAR
 *  - BIGINT (no UNSIGNED) instead of BIGINT UNSIGNED
 *  - VARCHAR(255) + CHECK instead of ENUM(…)
 *  - TEXT instead of SET(…)
 *  - JSONB instead of JSON
 *  - SMALLINT instead of TINYINT
 *  - no ENGINE=… suffix
 *  - ALTER COLUMN … TYPE / SET NOT NULL / SET DEFAULT instead of MODIFY COLUMN
 *  - DROP CONSTRAINT instead of DROP FOREIGN KEY
 *  - no FK-check toggling needed (PG DDL is transactional)
 */
final class PostgreSqlDialect implements SqlDialect
{
    public function quoteIdentifier(string $name): string
    {
        return "\"{$name}\"";
    }

    public function mapType(string $logicalType, int|string|null $length = null, ?array $enumValues = null): string
    {
        return match (strtolower($logicalType)) {
            'string'             => 'VARCHAR(' . ($length ?? 255) . ')',
            'char'               => 'CHAR(' . ($length ?? 1) . ')',
            'text'               => 'TEXT',
            'mediumtext'         => 'TEXT',
            'longtext'           => 'TEXT',
            'int', 'integer'     => 'INTEGER',
            'tinyint'            => 'SMALLINT',
            'smallint'           => 'SMALLINT',
            'bigint'             => 'BIGINT',
            'unsignedbigint'     => 'BIGINT',
            'decimal'            => 'NUMERIC(' . ($length ?? '10,2') . ')',
            'float', 'double'    => 'REAL',
            'boolean', 'bool'    => 'BOOLEAN',
            'date'               => 'DATE',
            'time'               => 'TIME',
            'datetime'           => 'TIMESTAMP',
            'datetimetz'         => 'TIMESTAMPTZ',
            'timestamp'          => 'TIMESTAMP',
            'timestamptz'        => 'TIMESTAMPTZ',
            'year'               => 'INTEGER',
            'uuid'               => 'UUID',
            'binary'             => 'BYTEA',
            'json'               => 'JSONB',
            'simple_json', 'array', 'simple_array' => 'TEXT',
            'enum'               => $this->pgEnumType($enumValues, $length),
            'set'                => 'TEXT',
            'geometry'           => 'GEOMETRY',
            'point'              => 'POINT',
            'linestring'         => 'GEOMETRY(LINESTRING)',
            'polygon'            => 'GEOMETRY(POLYGON)',
            'ipaddress'          => 'INET',
            'macaddress'         => 'MACADDR',
            default              => 'VARCHAR(' . ($length ?? 255) . ')',
        };
    }

    public function mapTypeWithNullability(
        string $logicalType,
        int|string|null $length = null,
        bool $nullable = false,
        ?array $enumValues = null,
    ): string {
        $null = $nullable ? ' NULL' : ' NOT NULL';
        return $this->mapType($logicalType, $length, $enumValues) . $null;
    }

    public function engineSuffix(): string
    {
        return ''; // PostgreSQL has no ENGINE clause
    }

    public function autoIncrementKeyword(): string
    {
        return ''; // PG uses SERIAL types, no separate keyword
    }

    public function autoIncrementType(string $baseType): string
    {
        return match (strtolower($baseType)) {
            'bigint', 'unsignedbigint' => 'BIGSERIAL',
            default                    => 'SERIAL',
        };
    }

    public function foreignKeyLookupSql(): string
    {
        return <<<'SQL'
SELECT tc.constraint_name
  FROM information_schema.table_constraints tc
  JOIN information_schema.key_column_usage kcu
    ON tc.constraint_name = kcu.constraint_name
   AND tc.table_schema    = kcu.table_schema
 WHERE tc.constraint_type = 'FOREIGN KEY'
   AND tc.table_catalog   = current_database()
   AND tc.table_schema    = 'public'
   AND tc.table_name      = :tbl
   AND kcu.column_name    = :col
 LIMIT 1
SQL;
    }

    public function foreignKeyLookupParams(string $table, string $column): array
    {
        return ['tbl' => $table, 'col' => $column];
    }

    public function disableFkChecks(): string
    {
        // PG DDL is transactional — no superuser-only toggle needed.
        // If the migration runner wraps in a transaction, failures simply roll back.
        return '';
    }

    public function enableFkChecks(): string
    {
        return '';
    }

    public function alterColumnSql(
        string $table,
        string $column,
        string $baseType,
        bool   $nullable,
        string $defaultClause,
    ): string {
        // PG requires separate sub-clauses inside one ALTER TABLE:
        //   ALTER COLUMN "c" TYPE <type>,
        //   ALTER COLUMN "c" SET/DROP NOT NULL
        //   [, ALTER COLUMN "c" SET/DROP DEFAULT …]
        $parts   = [];
        $parts[] = "ALTER COLUMN \"{$column}\" TYPE {$baseType}";
        $parts[] = $nullable
            ? "ALTER COLUMN \"{$column}\" DROP NOT NULL"
            : "ALTER COLUMN \"{$column}\" SET NOT NULL";

        if ($defaultClause !== '') {
            // $defaultClause arrives as " DEFAULT <value>"; extract the value.
            $defaultValue = preg_replace('/^\s*DEFAULT\s+/i', '', $defaultClause);
            $parts[] = "ALTER COLUMN \"{$column}\" SET DEFAULT {$defaultValue}";
        }

        return "ALTER TABLE \"{$table}\" " . implode(', ', $parts);
    }

    public function dropForeignKeySql(string $table, string $fkName): string
    {
        return "ALTER TABLE \"{$table}\" DROP CONSTRAINT \"{$fkName}\"";
    }

    public function uuidFkType(): string
    {
        return 'UUID';
    }

    public function intFkType(): string
    {
        return 'INTEGER';
    }

    // ── helpers ────────────────────────────────────────────────────

    /**
     * PostgreSQL has no native ENUM in DDL.
     * Map to VARCHAR(255); the caller can apply a CHECK constraint.
     */
    private function pgEnumType(?array $enumValues, int|string|null $length): string
    {
        return 'VARCHAR(' . ($length ?? 255) . ')';
    }
}
