<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Diff;

use MonkeysLegion\Migration\Schema\ColumnDefinition;
use MonkeysLegion\Migration\Schema\ForeignKeyDefinition;
use MonkeysLegion\Migration\Schema\IndexDefinition;
use MonkeysLegion\Migration\Schema\TableDefinition;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Compares desired schema (from entity attributes) with current schema
 * (from database introspection) and produces a structured DiffPlan.
 *
 * The differ produces pure data structures — no raw SQL. The SqlRenderer
 * is responsible for converting the DiffPlan into dialect-specific DDL.
 *
 * Features:
 *  - FK dependency ordering (topological sort for CREATE order)
 *  - Index diff detection
 *  - Column type/nullability/default change detection
 *  - Protected table filtering (e.g. ml_migrations)
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class SchemaDiffer
{
    /**
     * Tables that must never be dropped.
     *
     * @var list<string>
     */
    private array $protectedTables = [
        'migrations',
        'ml_migrations',
    ];

    /**
     * Compare desired schema with current schema.
     *
     * @param array<string, TableDefinition> $desired Desired state (from entities).
     * @param array<string, TableDefinition> $current Current state (from DB).
     *
     * @return DiffPlan Structured diff plan.
     */
    public function diff(array $desired, array $current): DiffPlan
    {
        $plan = new DiffPlan();

        // 1. Tables to CREATE (exist in desired but not in current)
        foreach ($desired as $tableName => $desiredTable) {
            if (!isset($current[$tableName])) {
                $plan->createTables[] = $desiredTable;
            }
        }

        // Sort CREATE order by FK dependencies (tables referenced first)
        $plan->createTables = $this->sortByDependencies($plan->createTables, $desired);

        // 2. Tables to ALTER (exist in both)
        foreach ($desired as $tableName => $desiredTable) {
            if (!isset($current[$tableName])) {
                continue;
            }

            $tableDiff = $this->diffTable($desiredTable, $current[$tableName]);

            if (!$tableDiff->isEmpty()) {
                $plan->alterTables[] = $tableDiff;
            }
        }

        // 3. Tables to DROP (exist in current but not in desired)
        foreach ($current as $tableName => $currentTable) {
            if (!isset($desired[$tableName]) && !$this->isProtected($tableName)) {
                $plan->dropTables[] = $tableName;
            }
        }

        return $plan;
    }

    /**
     * Add a protected table name.
     */
    public function addProtectedTable(string $table): void
    {
        $this->protectedTables[] = $table;
    }

    // ── Table-level diff ───────────────────────────────────────────

    /**
     * Diff a single table: columns, indexes, foreign keys.
     */
    private function diffTable(TableDefinition $desired, TableDefinition $current): TableDiff
    {
        $diff = new TableDiff(tableName: $desired->name);

        // Column diff
        $this->diffColumns($desired, $current, $diff);

        // Index diff
        $this->diffIndexes($desired, $current, $diff);

        // FK diff
        $this->diffForeignKeys($desired, $current, $diff);

        return $diff;
    }

    // ── Column diff ────────────────────────────────────────────────

    private function diffColumns(
        TableDefinition $desired,
        TableDefinition $current,
        TableDiff $diff,
    ): void {
        $currentNames = array_keys($current->columns);

        // Added columns
        foreach ($desired->columns as $colName => $desiredCol) {
            if (!isset($current->columns[$colName])) {
                $diff->addedColumns[] = $desiredCol;
            }
        }

        // Modified columns
        foreach ($desired->columns as $colName => $desiredCol) {
            if (!isset($current->columns[$colName])) {
                continue;
            }

            $currentCol = $current->columns[$colName];

            if ($this->columnsAreDifferent($desiredCol, $currentCol)) {
                $diff->modifiedColumns[] = new ColumnChange(
                    columnName: $colName,
                    from:       $currentCol,
                    to:         $desiredCol,
                );
            }
        }

        // Dropped columns (in current but not in desired, except PK)
        foreach ($currentNames as $colName) {
            if ($colName === $current->primaryKey) {
                continue;
            }

            if (!isset($desired->columns[$colName])) {
                $diff->droppedColumns[] = $colName;
            }
        }
    }

    /**
     * Compare two column definitions to determine if they differ.
     */
    private function columnsAreDifferent(ColumnDefinition $desired, ColumnDefinition $current): bool
    {
        // Type comparison (normalize both to lowercase)
        if (strtolower($desired->type) !== strtolower($current->type)) {
            return true;
        }

        // Nullability
        if ($desired->nullable !== $current->nullable) {
            return true;
        }

        // Default value
        if ($desired->default !== $current->default) {
            // Special case: introspected defaults may have quotes or type differences
            // Only flag as different if both are non-null and actually different
            if ($desired->default !== null && $current->default !== null) {
                $desiredStr = (string) $desired->default;
                $currentStr = trim((string) $current->default, "'\"");

                if ($desiredStr !== $currentStr) {
                    return true;
                }
            } elseif ($desired->default !== null || $current->default !== null) {
                return true;
            }
        }

        return false;
    }

    // ── Index diff ─────────────────────────────────────────────────

    private function diffIndexes(
        TableDefinition $desired,
        TableDefinition $current,
        TableDiff $diff,
    ): void {
        $currentIndexNames = array_map(
            static fn(IndexDefinition $idx): string => $idx->name,
            $current->indexes,
        );

        // Added indexes
        foreach ($desired->indexes as $desiredIdx) {
            if (!in_array($desiredIdx->name, $currentIndexNames, true)) {
                // Also check by columns — maybe the index exists with a different name
                if (!$this->indexExistsByColumns($desiredIdx, $current->indexes)) {
                    $diff->addedIndexes[] = $desiredIdx;
                }
            }
        }

        // Dropped indexes (in current but not in desired)
        $desiredIndexNames = array_map(
            static fn(IndexDefinition $idx): string => $idx->name,
            $desired->indexes,
        );

        foreach ($current->indexes as $currentIdx) {
            $nameMatch   = in_array($currentIdx->name, $desiredIndexNames, true);
            $columnMatch = $this->indexExistsByColumns($currentIdx, $desired->indexes);

            if (!$nameMatch && !$columnMatch) {
                $diff->droppedIndexes[] = $currentIdx->name;
            }
        }
    }

    /**
     * Check if an index with the same columns + uniqueness exists.
     *
     * @param list<IndexDefinition> $indexes
     */
    private function indexExistsByColumns(IndexDefinition $needle, array $indexes): bool
    {
        foreach ($indexes as $idx) {
            if ($idx->columns === $needle->columns && $idx->unique === $needle->unique) {
                return true;
            }
        }

        return false;
    }

    // ── FK diff ────────────────────────────────────────────────────

    private function diffForeignKeys(
        TableDefinition $desired,
        TableDefinition $current,
        TableDiff $diff,
    ): void {
        $currentFkCols = [];
        foreach ($current->foreignKeys as $fk) {
            $currentFkCols[$fk->column] = $fk;
        }

        // Added FKs
        foreach ($desired->foreignKeys as $desiredFk) {
            if (!isset($currentFkCols[$desiredFk->column])) {
                $diff->addedForeignKeys[] = $desiredFk;
                continue;
            }

            $currentFk = $currentFkCols[$desiredFk->column];
            if ($this->foreignKeysAreDifferent($desiredFk, $currentFk)) {
                $diff->addedForeignKeys[] = $desiredFk;
                $diff->droppedForeignKeys[] = $currentFk->name;
            }
        }

        // Dropped FKs (in current but not in desired)
        $desiredFkCols = [];
        foreach ($desired->foreignKeys as $fk) {
            $desiredFkCols[$fk->column] = $fk;
        }

        foreach ($current->foreignKeys as $currentFk) {
            if (!isset($desiredFkCols[$currentFk->column])) {
                $diff->droppedForeignKeys[] = $currentFk->name;
            }
        }
    }

    /**
     * Compare FK definitions by target + actions (name can differ and still be equivalent).
     */
    private function foreignKeysAreDifferent(ForeignKeyDefinition $desired, ForeignKeyDefinition $current): bool
    {
        return $desired->referencedTable !== $current->referencedTable
            || $desired->referencedColumn !== $current->referencedColumn
            || strtoupper($desired->onDelete) !== strtoupper($current->onDelete)
            || strtoupper($desired->onUpdate) !== strtoupper($current->onUpdate);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Check if a table is protected from being dropped.
     */
    private function isProtected(string $table): bool
    {
        return in_array($table, $this->protectedTables, true);
    }

    /**
     * Topological sort of tables by FK dependencies.
     *
     * Tables that are referenced by FK come first so they exist
     * before referencing tables are created.
     *
     * @param list<TableDefinition>            $tables
     * @param array<string, TableDefinition>   $allDesired
     *
     * @return list<TableDefinition>
     */
    private function sortByDependencies(array $tables, array $allDesired): array
    {
        if (count($tables) <= 1) {
            return $tables;
        }

        // Build adjacency: table → [tables it depends on]
        /** @var array<string, list<string>> */
        $deps = [];
        $reverseDeps = [];
        $tableMap = [];

        foreach ($tables as $table) {
            $tableMap[$table->name] = $table;
            $deps[$table->name] = [];
            $reverseDeps[$table->name] = [];
        }

        foreach ($tables as $table) {
            foreach ($table->foreignKeys as $fk) {
                $dependency = $fk->referencedTable;

                // Only add dependency if the referenced table is also being created
                if (
                    $dependency !== $table->name
                    && isset($allDesired[$dependency])
                    && isset($deps[$dependency])
                ) {
                    $deps[$table->name][] = $dependency;
                    $reverseDeps[$dependency][] = $table->name;
                }
            }
        }

        // Kahn's algorithm for topological sort
        // in-degree = number of dependencies each table has
        /** @var array<string, int> */
        $inDegree = [];
        foreach ($deps as $name => $dependencies) {
            $inDegree[$name] = count($dependencies);
        }

        $queue  = [];
        $sorted = [];

        // Start with tables that have NO dependencies (in-degree = 0)
        foreach ($inDegree as $name => $degree) {
            if ($degree === 0) {
                $queue[] = $name;
            }
        }

        for ($queueIndex = 0; $queueIndex < count($queue); $queueIndex++) {
            $name = $queue[$queueIndex];
            if (isset($tableMap[$name])) {
                $sorted[] = $tableMap[$name];
            }

            foreach ($reverseDeps[$name] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        // Any remaining tables (circular deps) get appended
        $sortedMap = [];
        foreach ($sorted as $sortedTable) {
            $sortedMap[$sortedTable->name] = true;
        }

        foreach ($tables as $table) {
            if (!isset($sortedMap[$table->name])) {
                $sorted[] = $table;
            }
        }

        return $sorted;
    }
}
