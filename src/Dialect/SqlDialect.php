<?php

declare(strict_types=1);

namespace MonkeysLegion\Migration\Dialect;

/**
 * Dialect-specific SQL generation contract.
 *
 * Each implementation (MySQL, PostgreSQL, …) supplies quoting rules,
 * type mappings, and syntactic fragments that differ across engines.
 */
interface SqlDialect
{
    /** Wrap an identifier (table / column name) in engine-appropriate quotes. */
    public function quoteIdentifier(string $name): string;

    /**
     * Map a logical type to the raw SQL column type (no NULL suffix).
     *
     * @param string          $logicalType  e.g. 'string', 'boolean', 'enum'
     * @param int|string|null $length       optional length / precision
     * @param array|null      $enumValues   enum/set value list
     */
    public function mapType(string $logicalType, int|string|null $length = null, ?array $enumValues = null): string;

    /**
     * Map a logical type to a full column type including NULL / NOT NULL.
     */
    public function mapTypeWithNullability(
        string $logicalType,
        int|string|null $length = null,
        bool $nullable = false,
        ?array $enumValues = null,
    ): string;

    /** Suffix appended to CREATE TABLE (e.g. ENGINE=InnoDB …). */
    public function engineSuffix(): string;

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

    /** Prepared-statement SQL to look up a FK constraint name. */
    public function foreignKeyLookupSql(): string;

    /** Parameters (assoc) to bind for the FK lookup. */
    public function foreignKeyLookupParams(string $table, string $column): array;

    /** Statement to disable FK checks before destructive DDL. */
    public function disableFkChecks(): string;

    /** Statement to re-enable FK checks. */
    public function enableFkChecks(): string;

    /**
     * Generate ALTER … to change a column's type, nullability, and/or default.
     *
     * Each dialect composes these parts using its own syntax:
     *   MySQL  → ALTER TABLE `t` MODIFY COLUMN `c` <type> <null> <default>
     *   PG     → ALTER TABLE "t" ALTER COLUMN "c" TYPE <type>,
     *                            ALTER COLUMN "c" SET/DROP NOT NULL
     *                            [, ALTER COLUMN "c" SET DEFAULT …]
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

    /** Generate ALTER … to drop a foreign key constraint. */
    public function dropForeignKeySql(string $table, string $fkName): string;

    /** SQL type for a FK column referencing a UUID primary key. */
    public function uuidFkType(): string;

    /** SQL type for a FK column referencing an integer primary key. */
    public function intFkType(): string;
}
