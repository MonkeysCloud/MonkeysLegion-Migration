<?php

declare(strict_types=1);

namespace MonkeysLegion\Migration\Dialect;

/**
 * MySQL-specific SQL generation.
 *
 * Preserves all behaviour that MigrationGenerator had before the refactor:
 * backtick quoting, ENGINE=InnoDB, AUTO_INCREMENT, MySQL type mappings, etc.
 */
final class MySqlDialect implements SqlDialect
{
    public function quoteIdentifier(string $name): string
    {
        return "`{$name}`";
    }

    public function mapType(string $logicalType, int|string|null $length = null, ?array $enumValues = null): string
    {
        return match (strtolower($logicalType)) {
            'string'             => 'VARCHAR(' . ($length ?? 255) . ')',
            'char'               => 'CHAR(' . ($length ?? 1) . ')',
            'text'               => 'TEXT',
            'mediumtext'         => 'MEDIUMTEXT',
            'longtext'           => 'LONGTEXT',
            'int', 'integer'     => 'INT',
            'tinyint'            => 'TINYINT' . ($length ? "($length)" : '(1)'),
            'smallint'           => 'SMALLINT',
            'bigint'             => 'BIGINT',
            'unsignedbigint'     => 'BIGINT UNSIGNED',
            'decimal'            => 'DECIMAL(' . ($length ?? '10,2') . ')',
            'float', 'double'    => 'FLOAT',
            'boolean', 'bool'    => 'TINYINT(1)',
            'date'               => 'DATE',
            'time'               => 'TIME',
            'datetime'           => 'DATETIME',
            'datetimetz'         => 'DATETIME',
            'timestamp'          => 'TIMESTAMP',
            'timestamptz'        => 'TIMESTAMP',
            'year'               => 'YEAR',
            'uuid'               => 'CHAR(36)',
            'binary'             => 'BLOB',
            'json'               => 'JSON',
            'simple_json', 'array', 'simple_array' => 'TEXT',
            'enum'               => 'ENUM(' . ($enumValues ? $this->formatEnumValues($enumValues) : ($length ?? "'value1','value2'")) . ')',
            'set'                => 'SET(' . ($enumValues ? $this->formatEnumValues($enumValues) : ($length ?? "'value1','value2'")) . ')',
            'geometry'           => 'GEOMETRY',
            'point'              => 'POINT',
            'linestring'         => 'LINESTRING',
            'polygon'            => 'POLYGON',
            'ipaddress'          => 'VARCHAR(45)',
            'macaddress'         => 'VARCHAR(17)',
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
        return ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    public function autoIncrementKeyword(): string
    {
        return ' AUTO_INCREMENT';
    }

    public function autoIncrementType(string $baseType): string
    {
        // MySQL keeps the original type and appends AUTO_INCREMENT separately.
        return $this->mapType($baseType);
    }

    public function foreignKeyLookupSql(): string
    {
        return <<<'SQL'
SELECT CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = :tbl
   AND COLUMN_NAME  = :col
   AND REFERENCED_TABLE_NAME IS NOT NULL
 LIMIT 1
SQL;
    }

    public function foreignKeyLookupParams(string $table, string $column): array
    {
        return ['tbl' => $table, 'col' => $column];
    }

    public function disableFkChecks(): string
    {
        return 'SET FOREIGN_KEY_CHECKS=0;';
    }

    public function enableFkChecks(): string
    {
        return 'SET FOREIGN_KEY_CHECKS=1;';
    }

    public function alterColumnSql(
        string $table,
        string $column,
        string $baseType,
        bool   $nullable,
        string $defaultClause,
    ): string {
        $null = $nullable ? ' NULL' : ' NOT NULL';
        return "ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$baseType}{$null}{$defaultClause}";
    }

    public function dropForeignKeySql(string $table, string $fkName): string
    {
        return "ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fkName}`";
    }

    public function uuidFkType(): string
    {
        return 'CHAR(36)';
    }

    public function intFkType(): string
    {
        return 'INT';
    }

    // ── helpers ────────────────────────────────────────────────────

    private function formatEnumValues(array $values): string
    {
        return implode(',', array_map(fn($v) => "'" . addslashes((string) $v) . "'", $values));
    }
}
