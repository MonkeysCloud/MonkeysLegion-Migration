<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Diff;

use MonkeysLegion\Migration\Schema\TableDefinition;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Complete diff plan between desired and current schema.
 *
 * This is the output of SchemaDiffer and the input to SqlRenderer.
 * It contains structured data — no raw SQL — so it can be
 * rendered for any dialect or displayed as a human-readable report.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class DiffPlan
{
    /**
     * @param list<TableDefinition> $createTables Tables to create (full definitions).
     * @param list<TableDiff>       $alterTables  Tables to alter (per-table diffs).
     * @param list<string>          $dropTables   Table names to drop.
     */
    public function __construct(
        public array $createTables = [],
        public array $alterTables = [],
        public array $dropTables = [],
    ) {}

    /**
     * Whether the diff plan has any changes.
     */
    public function isEmpty(): bool
    {
        if ($this->createTables !== [] || $this->dropTables !== []) {
            return false;
        }

        foreach ($this->alterTables as $tableDiff) {
            if (!$tableDiff->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Human-readable summary (for --pretend mode).
     */
    public function toHumanReadable(): string
    {
        if ($this->isEmpty()) {
            return 'Schema is already up to date.';
        }

        $lines = [];

        foreach ($this->createTables as $table) {
            $colCount = count($table->columns);
            $idxCount = count($table->indexes);
            $fkCount  = count($table->foreignKeys);
            $lines[]  = "CREATE TABLE {$table->name} ({$colCount} columns, {$idxCount} indexes, {$fkCount} FKs)";
        }

        foreach ($this->alterTables as $diff) {
            if (!$diff->isEmpty()) {
                $lines[] = "ALTER TABLE {$diff->tableName}: {$diff->describe()}";
            }
        }

        foreach ($this->dropTables as $table) {
            $lines[] = "DROP TABLE {$table}";
        }

        return implode("\n", $lines);
    }

    /**
     * Count total number of individual changes.
     */
    public function changeCount(): int
    {
        $count = count($this->createTables) + count($this->dropTables);

        foreach ($this->alterTables as $diff) {
            $count += count($diff->addedColumns);
            $count += count($diff->modifiedColumns);
            $count += count($diff->droppedColumns);
            $count += count($diff->addedIndexes);
            $count += count($diff->droppedIndexes);
            $count += count($diff->addedForeignKeys);
            $count += count($diff->droppedForeignKeys);
        }

        return $count;
    }
}
