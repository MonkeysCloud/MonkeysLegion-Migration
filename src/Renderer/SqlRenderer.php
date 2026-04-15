<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Renderer;

use MonkeysLegion\Migration\Diff\ColumnChange;
use MonkeysLegion\Migration\Diff\DiffPlan;
use MonkeysLegion\Migration\Diff\TableDiff;
use MonkeysLegion\Migration\Dialect\SqlDialect;
use MonkeysLegion\Migration\Schema\ColumnDefinition;
use MonkeysLegion\Migration\Schema\ForeignKeyDefinition;
use MonkeysLegion\Migration\Schema\IndexDefinition;
use MonkeysLegion\Migration\Schema\TableDefinition;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Converts a structured DiffPlan into dialect-specific SQL statements.
 *
 * Separated from the differ so the same diff can be rendered for
 * MySQL, PostgreSQL, or SQLite without re-computing it.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class SqlRenderer
{
    public function __construct(
        private readonly SqlDialect $dialect,
    ) {}

    // ── Public API ─────────────────────────────────────────────────

    /**
     * Render a DiffPlan into a single SQL string.
     */
    public function render(DiffPlan $plan): string
    {
        $statements = $this->renderStatements($plan);

        if ($statements === []) {
            return '';
        }

        $disableFk = $this->dialect->disableFkChecks();
        $enableFk  = $this->dialect->enableFkChecks();

        $sql = implode(";\n", $statements) . ';';

        if ($disableFk !== '' && $enableFk !== '') {
            $sql = "{$disableFk}\n{$sql}\n{$enableFk}";
        }

        return $sql;
    }

    /**
     * Render a DiffPlan into individual SQL statements (no trailing semicolons).
     *
     * @return list<string>
     */
    public function renderStatements(DiffPlan $plan): array
    {
        $stmts = [];

        // 1. CREATE TABLE statements (dependency-ordered)
        foreach ($plan->createTables as $table) {
            $stmts[] = $this->renderCreateTable($table);

            // CREATE INDEX statements for the new table
            foreach ($table->indexes as $idx) {
                $stmts[] = $this->renderCreateIndex($table->name, $idx);
            }

            // FK constraints as separate ALTER TABLE (for cross-table deps)
            foreach ($table->foreignKeys as $fk) {
                $stmts[] = $this->renderAddForeignKey($table->name, $fk);
            }
        }

        // 2. ALTER TABLE statements
        foreach ($plan->alterTables as $diff) {
            $stmts = array_merge($stmts, $this->renderAlterTable($diff));
        }

        // 3. DROP TABLE statements (last)
        foreach ($plan->dropTables as $tableName) {
            $q = $this->dialect->quoteIdentifier($tableName);
            $stmts[] = "DROP TABLE IF EXISTS {$q}";
        }

        return $stmts;
    }

    /**
     * Render the reverse (rollback) SQL for a DiffPlan.
     */
    public function renderReverse(DiffPlan $plan): string
    {
        $stmts = [];

        // Reverse drops → creates (we don't have full info, so just mark as TODO)
        foreach ($plan->dropTables as $table) {
            $stmts[] = "-- TODO: Recreate table {$table}";
        }

        // Reverse alters
        foreach (array_reverse($plan->alterTables) as $diff) {
            foreach (array_reverse($diff->addedColumns) as $col) {
                $q  = $this->dialect->quoteIdentifier($diff->tableName);
                $qc = $this->dialect->quoteIdentifier($col->effectiveName);
                $stmts[] = "ALTER TABLE {$q} DROP COLUMN {$qc}";
            }

            foreach (array_reverse($diff->droppedColumns) as $colName) {
                $stmts[] = "-- TODO: Recreate column {$diff->tableName}.{$colName}";
            }

            foreach (array_reverse($diff->addedIndexes) as $idx) {
                $stmts[] = $this->dialect->dropIndexSql($diff->tableName, $idx->name);
            }

            foreach (array_reverse($diff->addedForeignKeys) as $fk) {
                $stmts[] = $this->dialect->dropForeignKeySql($diff->tableName, $fk->name);
            }
        }

        // Reverse creates → drops
        foreach (array_reverse($plan->createTables) as $table) {
            $q = $this->dialect->quoteIdentifier($table->name);
            $stmts[] = "DROP TABLE IF EXISTS {$q}";
        }

        if ($stmts === []) {
            return '';
        }

        return implode(";\n", $stmts) . ';';
    }

    // ── Private renderers ──────────────────────────────────────────

    /**
     * Render CREATE TABLE statement.
     */
    private function renderCreateTable(TableDefinition $table): string
    {
        $q    = fn(string $id): string => $this->dialect->quoteIdentifier($id);
        $defs = [];

        foreach ($table->columns as $col) {
            $defs[] = $this->renderColumnDef($col);
        }

        // Primary key
        $defs[] = "PRIMARY KEY ({$q($table->primaryKey)})";

        $defsStr = implode(",\n  ", $defs);
        $suffix  = $this->dialect->engineSuffix();
        $suffix  = $suffix !== '' ? "\n{$suffix}" : '';

        return "CREATE TABLE {$q($table->name)} (\n  {$defsStr}\n){$suffix}";
    }

    /**
     * Render a column definition for CREATE TABLE.
     */
    private function renderColumnDef(ColumnDefinition $col): string
    {
        $q    = $this->dialect->quoteIdentifier($col->effectiveName);
        $type = $col->type;

        if ($col->autoIncrement) {
            $sqlType    = $this->dialect->autoIncrementType($type);
            $null       = $col->nullable ? ' NULL' : ' NOT NULL';
            $autoSuffix = $this->dialect->autoIncrementKeyword();
            $default    = $this->renderDefault($col->default, $type);

            return "{$q} {$sqlType}{$null}{$autoSuffix}{$default}";
        }

        $sqlType = $this->dialect->mapTypeWithNullability(
            $type,
            $col->length,
            $col->nullable,
            $col->enumValues,
        );
        $default = $this->renderDefault($col->default, $type);

        return "{$q} {$sqlType}{$default}";
    }

    /**
     * Render ALTER TABLE statements for a table diff.
     *
     * @return list<string>
     */
    private function renderAlterTable(TableDiff $diff): array
    {
        $q    = fn(string $id): string => $this->dialect->quoteIdentifier($id);
        $stmts = [];

        // Drop FKs first (so columns can be dropped)
        foreach ($diff->droppedForeignKeys as $fkName) {
            $stmts[] = $this->dialect->dropForeignKeySql($diff->tableName, $fkName);
        }

        // Drop indexes
        foreach ($diff->droppedIndexes as $idxName) {
            $stmts[] = $this->dialect->dropIndexSql($diff->tableName, $idxName);
        }

        // Add columns
        foreach ($diff->addedColumns as $col) {
            $colDef = $this->renderColumnDef($col);
            $stmts[] = "ALTER TABLE {$q($diff->tableName)} ADD COLUMN {$colDef}";
        }

        // Modify columns
        foreach ($diff->modifiedColumns as $change) {
            $to = $change->to;
            $baseType      = $this->dialect->mapType($to->type, $to->length, $to->enumValues);
            $defaultClause = $this->renderDefault($to->default, $to->type);

            $stmts[] = $this->dialect->alterColumnSql(
                $diff->tableName,
                $change->columnName,
                $baseType,
                $to->nullable,
                $defaultClause,
            );
        }

        // Drop columns
        foreach ($diff->droppedColumns as $colName) {
            $stmts[] = "ALTER TABLE {$q($diff->tableName)} DROP COLUMN {$q($colName)}";
        }

        // Add indexes
        foreach ($diff->addedIndexes as $idx) {
            $stmts[] = $this->renderCreateIndex($diff->tableName, $idx);
        }

        // Add FKs
        foreach ($diff->addedForeignKeys as $fk) {
            $stmts[] = $this->renderAddForeignKey($diff->tableName, $fk);
        }

        return $stmts;
    }

    /**
     * Render CREATE INDEX statement.
     */
    private function renderCreateIndex(string $table, IndexDefinition $idx): string
    {
        $q       = fn(string $id): string => $this->dialect->quoteIdentifier($id);
        $unique  = $idx->unique ? 'UNIQUE ' : '';
        $columns = implode(', ', array_map($q, $idx->columns));

        return "CREATE {$unique}INDEX {$q($idx->name)} ON {$q($table)} ({$columns})";
    }

    /**
     * Render ALTER TABLE ADD FOREIGN KEY statement.
     */
    private function renderAddForeignKey(string $table, ForeignKeyDefinition $fk): string
    {
        $q = fn(string $id): string => $this->dialect->quoteIdentifier($id);

        $sql = "ALTER TABLE {$q($table)} "
            . "ADD CONSTRAINT {$q($fk->name)} "
            . "FOREIGN KEY ({$q($fk->column)}) "
            . "REFERENCES {$q($fk->referencedTable)}({$q($fk->referencedColumn)})";

        if ($fk->onDelete !== 'RESTRICT') {
            $sql .= " ON DELETE {$fk->onDelete}";
        }

        if ($fk->onUpdate !== 'RESTRICT') {
            $sql .= " ON UPDATE {$fk->onUpdate}";
        }

        return $sql;
    }

    /**
     * Render DEFAULT clause for a column.
     */
    private function renderDefault(mixed $value, string $phpType): string
    {
        if ($value === null) {
            return '';
        }

        $typeLower = strtolower($phpType);

        // Boolean
        if ($typeLower === 'boolean' || $typeLower === 'bool') {
            return ' DEFAULT ' . ($value ? 'TRUE' : 'FALSE');
        }

        // Enum/Set
        if ($typeLower === 'enum' || $typeLower === 'set') {
            return " DEFAULT '" . addslashes((string) $value) . "'";
        }

        // Determine if quotes are needed
        $needsQuotes = match ($typeLower) {
            'string', 'text', 'char', 'uuid', 'json', 'simple_json',
            'array', 'simple_array', 'mediumtext', 'longtext' => true,
            default => false,
        };

        $literal = $needsQuotes
            ? "'" . addslashes((string) $value) . "'"
            : (string) $value;

        return " DEFAULT {$literal}";
    }
}
