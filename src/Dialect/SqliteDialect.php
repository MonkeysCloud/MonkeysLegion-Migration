<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Dialect;

use MonkeysLegion\Migration\Security\IdentifierValidator;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * SQLite-specific SQL generation.
 *
 * SQLite limitations handled:
 *  - No ALTER COLUMN (only ADD COLUMN supported)
 *  - No DROP COLUMN before SQLite 3.35.0
 *  - AUTOINCREMENT on INTEGER PRIMARY KEY only
 *  - Type affinity instead of strict types
 *  - No native ENUM, SET, UUID, INET types
 *  - No table ENGINE or charset
 *  - Transactional DDL is supported
 *
 * Ideal for testing without an external database server.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class SqliteDialect implements SqlDialect
{
    // ── Identifier quoting ─────────────────────────────────────────

    public function quoteIdentifier(string $name): string
    {
        IdentifierValidator::validate($name);

        return "\"{$name}\"";
    }

    // ── Type mapping ───────────────────────────────────────────────

    public function mapType(
        string $logicalType,
        int|string|null $length = null,
        ?array $enumValues = null,
    ): string {
        // SQLite uses type affinity — we map to the closest affinity
        return match (strtolower($logicalType)) {
            'string', 'char', 'uuid', 'ulid',
            'ipaddress', 'macaddress'       => 'TEXT',
            'text', 'mediumtext', 'longtext' => 'TEXT',
            'int', 'integer', 'tinyint',
            'smallint', 'bigint',
            'unsignedbigint', 'year'         => 'INTEGER',
            'decimal', 'float', 'double'     => 'REAL',
            'boolean', 'bool'                => 'INTEGER',
            'date', 'time', 'datetime',
            'datetimetz', 'timestamp',
            'timestamptz'                    => 'TEXT',
            'binary', 'blob'                 => 'BLOB',
            'json', 'simple_json', 'array',
            'simple_array', 'vector'         => 'TEXT',
            'enum', 'set'                    => 'TEXT',
            'geometry', 'point',
            'linestring', 'polygon'          => 'TEXT',
            default                          => 'TEXT',
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
        // SQLite: INTEGER PRIMARY KEY implies AUTOINCREMENT (rowid alias)
        return 'INTEGER';
    }

    // ── Foreign key operations ─────────────────────────────────────

    public function foreignKeyLookupSql(): string
    {
        // SQLite uses PRAGMA, not information_schema
        // This returns a dummy SQL — actual FK lookup uses PRAGMA foreign_key_list
        return "SELECT '' AS constraint_name WHERE 0";
    }

    public function foreignKeyLookupParams(string $table, string $column): array
    {
        return ['tbl' => $table, 'col' => $column];
    }

    public function dropForeignKeySql(string $table, string $fkName): string
    {
        // SQLite does not support DROP FOREIGN KEY
        return sprintf(
            '-- SQLite: Cannot drop FK %s from %s (not supported)',
            $this->quoteIdentifier($fkName),
            $this->quoteIdentifier($table),
        );
    }

    public function uuidFkType(): string
    {
        return 'TEXT';
    }

    public function intFkType(): string
    {
        return 'INTEGER';
    }

    // ── FK check toggling ──────────────────────────────────────────

    public function disableFkChecks(): string
    {
        return 'PRAGMA foreign_keys = OFF;';
    }

    public function enableFkChecks(): string
    {
        return 'PRAGMA foreign_keys = ON;';
    }

    // ── Column operations ──────────────────────────────────────────

    public function alterColumnSql(
        string $table,
        string $column,
        string $baseType,
        bool   $nullable,
        string $defaultClause,
    ): string {
        // SQLite does not support ALTER COLUMN — requires table rebuild
        $null = $nullable ? ' NULL' : ' NOT NULL';

        return "-- SQLite: ALTER COLUMN not supported. "
            . sprintf(
                'Would change %s.%s to %s%s%s',
                $this->quoteIdentifier($table),
                $this->quoteIdentifier($column),
                $baseType,
                $null,
                $defaultClause,
            );
    }

    public function renameColumnSql(string $table, string $from, string $to): string
    {
        // Supported since SQLite 3.25.0
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
        return "DROP INDEX IF EXISTS {$this->quoteIdentifier($indexName)}";
    }

    // ── Transaction support ────────────────────────────────────────

    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    // ── Table comment ──────────────────────────────────────────────

    public function tableCommentSql(string $table, string $comment): string
    {
        // SQLite does not support table comments
        return '';
    }
}
