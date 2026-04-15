<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Dialect;

use MonkeysLegion\Migration\Security\IdentifierValidator;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * MySQL-specific SQL generation.
 *
 * Covers: backtick quoting, ENGINE=InnoDB, AUTO_INCREMENT, MySQL type
 * mappings, index management, FK operations, and column renaming.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class MySqlDialect implements SqlDialect
{
    // ── Identifier quoting ─────────────────────────────────────────

    public function quoteIdentifier(string $name): string
    {
        IdentifierValidator::validate($name);

        return "`{$name}`";
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
            'ulid'               => 'CHAR(26)',
            'binary', 'blob'     => 'BLOB',
            'json'               => 'JSON',
            'simple_json', 'array', 'simple_array' => 'TEXT',
            'enum'               => 'ENUM(' . ($enumValues
                ? $this->formatEnumValues($enumValues)
                : ($length ?? "'value1','value2'")) . ')',
            'set'                => 'SET(' . ($enumValues
                ? $this->formatEnumValues($enumValues)
                : ($length ?? "'value1','value2'")) . ')',
            'geometry'           => 'GEOMETRY',
            'point'              => 'POINT',
            'linestring'         => 'LINESTRING',
            'polygon'            => 'POLYGON',
            'ipaddress'          => 'VARCHAR(45)',
            'macaddress'         => 'VARCHAR(17)',
            'vector'             => 'JSON',
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
        return ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    // ── Auto-increment ─────────────────────────────────────────────

    public function autoIncrementKeyword(): string
    {
        return ' AUTO_INCREMENT';
    }

    public function autoIncrementType(string $baseType): string
    {
        return $this->mapType($baseType);
    }

    // ── Foreign key operations ─────────────────────────────────────

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

    public function dropForeignKeySql(string $table, string $fkName): string
    {
        return "ALTER TABLE {$this->quoteIdentifier($table)} DROP FOREIGN KEY {$this->quoteIdentifier($fkName)}";
    }

    public function uuidFkType(): string
    {
        return 'CHAR(36)';
    }

    public function intFkType(): string
    {
        return 'INT';
    }

    // ── FK check toggling ──────────────────────────────────────────

    public function disableFkChecks(): string
    {
        return 'SET FOREIGN_KEY_CHECKS=0;';
    }

    public function enableFkChecks(): string
    {
        return 'SET FOREIGN_KEY_CHECKS=1;';
    }

    // ── Column operations ──────────────────────────────────────────

    public function alterColumnSql(
        string $table,
        string $column,
        string $baseType,
        bool   $nullable,
        string $defaultClause,
    ): string {
        $null = $nullable ? ' NULL' : ' NOT NULL';

        return sprintf(
            'ALTER TABLE %s MODIFY COLUMN %s %s%s%s',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($column),
            $baseType,
            $null,
            $defaultClause,
        );
    }

    public function renameColumnSql(string $table, string $from, string $to): string
    {
        return sprintf(
            'ALTER TABLE %s RENAME COLUMN %s TO %s',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($from),
            $this->quoteIdentifier($to),
        );
    }

    // ── Index operations ───────────────────────────────────────────

    public function dropIndexSql(string $table, string $indexName): string
    {
        return "DROP INDEX {$this->quoteIdentifier($indexName)} ON {$this->quoteIdentifier($table)}";
    }

    // ── Transaction support ────────────────────────────────────────

    public function supportsTransactionalDdl(): bool
    {
        return false;
    }

    // ── Table comment ──────────────────────────────────────────────

    public function tableCommentSql(string $table, string $comment): string
    {
        $escaped = addslashes($comment);

        return "ALTER TABLE {$this->quoteIdentifier($table)} COMMENT = '{$escaped}'";
    }

    // ── Private helpers ────────────────────────────────────────────

    private function formatEnumValues(array $values): string
    {
        return implode(',', array_map(
            fn($v) => "'" . addslashes((string) $v) . "'",
            $values,
        ));
    }
}
