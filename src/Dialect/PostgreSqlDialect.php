<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Dialect;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * PostgreSQL-specific SQL generation.
 *
 * Key differences from MySQL:
 *  - Double-quote identifiers instead of backticks
 *  - SERIAL / BIGSERIAL instead of AUTO_INCREMENT
 *  - BOOLEAN instead of TINYINT(1)
 *  - JSONB instead of JSON
 *  - BYTEA instead of BLOB
 *  - ALTER COLUMN … TYPE / SET NOT NULL syntax
 *  - DROP CONSTRAINT instead of DROP FOREIGN KEY
 *  - Transactional DDL support
 *  - Native INET / MACADDR / UUID types
 *  - GIN / GIST index types for JSONB / full-text
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class PostgreSqlDialect implements SqlDialect
{
    // ── Identifier quoting ─────────────────────────────────────────

    public function quoteIdentifier(string $name): string
    {
        return "\"{$name}\"";
    }

    // ── Type mapping ───────────────────────────────────────────────

    public function mapType(
        string $logicalType,
        int|string|null $length = null,
        ?array $enumValues = null,
    ): string {
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
            'ulid'               => 'CHAR(26)',
            'binary', 'blob'     => 'BYTEA',
            'json'               => 'JSONB',
            'simple_json', 'array', 'simple_array' => 'TEXT',
            'enum'               => $this->pgEnumType($enumValues, $length),
            'set'                => 'TEXT',
            'geometry'           => 'BYTEA',
            'point'              => 'POINT',
            'linestring'         => 'PATH',
            'polygon'            => 'POLYGON',
            'ipaddress'          => 'INET',
            'macaddress'         => 'MACADDR',
            'vector'             => 'JSONB',
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

    // ── Table DDL ──────────────────────────────────────────────────

    public function engineSuffix(): string
    {
        return '';
    }

    // ── Auto-increment ─────────────────────────────────────────────

    public function autoIncrementKeyword(): string
    {
        return '';
    }

    public function autoIncrementType(string $baseType): string
    {
        return match (strtolower($baseType)) {
            'bigint', 'unsignedbigint' => 'BIGSERIAL',
            'smallint'                 => 'SMALLSERIAL',
            default                    => 'SERIAL',
        };
    }

    // ── Foreign key operations ─────────────────────────────────────

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
   AND tc.table_schema    = current_schema()
   AND tc.table_name      = :tbl
   AND kcu.column_name    = :col
 LIMIT 1
SQL;
    }

    public function foreignKeyLookupParams(string $table, string $column): array
    {
        return ['tbl' => $table, 'col' => $column];
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

    // ── FK check toggling ──────────────────────────────────────────

    public function disableFkChecks(): string
    {
        return '';
    }

    public function enableFkChecks(): string
    {
        return '';
    }

    // ── Column operations ──────────────────────────────────────────

    public function alterColumnSql(
        string $table,
        string $column,
        string $baseType,
        bool   $nullable,
        string $defaultClause,
    ): string {
        $parts   = [];
        $parts[] = "ALTER COLUMN \"{$column}\" TYPE {$baseType}";
        $parts[] = $nullable
            ? "ALTER COLUMN \"{$column}\" DROP NOT NULL"
            : "ALTER COLUMN \"{$column}\" SET NOT NULL";

        if ($defaultClause !== '') {
            $defaultValue = preg_replace('/^\s*DEFAULT\s+/i', '', $defaultClause);
            $parts[] = "ALTER COLUMN \"{$column}\" SET DEFAULT {$defaultValue}";
        }

        return "ALTER TABLE \"{$table}\" " . implode(', ', $parts);
    }

    public function renameColumnSql(string $table, string $from, string $to): string
    {
        return "ALTER TABLE \"{$table}\" RENAME COLUMN \"{$from}\" TO \"{$to}\"";
    }

    // ── Index operations ───────────────────────────────────────────

    public function dropIndexSql(string $table, string $indexName): string
    {
        return "DROP INDEX \"{$indexName}\"";
    }

    // ── Transaction support ────────────────────────────────────────

    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    // ── Table comment ──────────────────────────────────────────────

    public function tableCommentSql(string $table, string $comment): string
    {
        $escaped = str_replace("'", "''", $comment);

        return "COMMENT ON TABLE \"{$table}\" IS '{$escaped}'";
    }

    // ── Private helpers ────────────────────────────────────────────

    /**
     * PostgreSQL has no native ENUM in CREATE TABLE DDL.
     * Map to VARCHAR(255); callers should apply CHECK constraints if needed.
     */
    private function pgEnumType(?array $enumValues, int|string|null $length): string
    {
        return 'VARCHAR(' . ($length ?? 255) . ')';
    }
}
