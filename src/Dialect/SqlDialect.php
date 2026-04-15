<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Dialect;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Dialect-specific SQL generation contract.
 *
 * Each implementation (MySQL, PostgreSQL, SQLite) supplies quoting rules,
 * type mappings, index DDL, FK DDL, and syntactic fragments that differ
 * across database engines.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
interface SqlDialect
{
    // ── Identifier quoting ─────────────────────────────────────────

    /** Wrap an identifier (table / column name) in engine-appropriate quotes. */
    public function quoteIdentifier(string $name): string;

    // ── Type mapping ───────────────────────────────────────────────

    /**
     * Map a logical type to the raw SQL column type (no NULL suffix).
     *
     * @param string          $logicalType  e.g. 'string', 'boolean', 'enum'
     * @param int|string|null $length       optional length / precision
     * @param list<string>|null $enumValues enum/set value list
     */
    public function mapType(
        string $logicalType,
        int|string|null $length = null,
        ?array $enumValues = null,
    ): string;

    /**
     * Map a logical type to a full column type including NULL / NOT NULL.
     */
    public function mapTypeWithNullability(
        string $logicalType,
        int|string|null $length = null,
        bool $nullable = false,
        ?array $enumValues = null,
    ): string;

    // ── Table DDL ──────────────────────────────────────────────────

    /** Suffix appended to CREATE TABLE (e.g. ENGINE=InnoDB …). */
    public function engineSuffix(): string;

    // ── Auto-increment ─────────────────────────────────────────────

    /**
     * Keyword appended *after* a column type to mark it as auto-increment.
     * MySQL → ' AUTO_INCREMENT';  PG returns '' (uses SERIAL type instead).
     */
    public function autoIncrementKeyword(): string;

    /**
     * Return the SQL column type for an auto-increment primary key.
     *
     * @param string $baseType  The logical type (e.g. 'int', 'bigint').
     * @return string           MySQL → 'INT';  PG → 'SERIAL' / 'BIGSERIAL'.
     */
    public function autoIncrementType(string $baseType): string;

    // ── Foreign key operations ─────────────────────────────────────

    /** Prepared-statement SQL to look up a FK constraint name. */
    public function foreignKeyLookupSql(): string;

    /** Parameters (assoc) to bind for the FK lookup. */
    public function foreignKeyLookupParams(string $table, string $column): array;

    /** Generate ALTER … to drop a foreign key constraint. */
    public function dropForeignKeySql(string $table, string $fkName): string;

    /** SQL type for a FK column referencing a UUID primary key. */
    public function uuidFkType(): string;

    /** SQL type for a FK column referencing an integer primary key. */
    public function intFkType(): string;

    // ── FK check toggling ──────────────────────────────────────────

    /** Statement to disable FK checks before destructive DDL. */
    public function disableFkChecks(): string;

    /** Statement to re-enable FK checks. */
    public function enableFkChecks(): string;

    // ── Column operations ──────────────────────────────────────────

    /**
     * Generate ALTER … to change a column's type, nullability, and/or default.
     *
     * @param string $defaultClause  Rendered default (e.g. " DEFAULT 'x'" or "")
     */
    public function alterColumnSql(
        string $table,
        string $column,
        string $baseType,
        bool   $nullable,
        string $defaultClause,
    ): string;

    /**
     * Generate ALTER TABLE … RENAME COLUMN.
     */
    public function renameColumnSql(string $table, string $from, string $to): string;

    // ── Index operations ───────────────────────────────────────────

    /**
     * Generate DROP INDEX statement.
     */
    public function dropIndexSql(string $table, string $indexName): string;

    // ── Transaction support ────────────────────────────────────────

    /**
     * Whether this dialect supports transactional DDL.
     * PG: true, MySQL: false, SQLite: true (sort of).
     */
    public function supportsTransactionalDdl(): bool;

    // ── Table comment ──────────────────────────────────────────────

    /**
     * Generate a COMMENT ON TABLE statement, or empty string if unsupported.
     */
    public function tableCommentSql(string $table, string $comment): string;
}
