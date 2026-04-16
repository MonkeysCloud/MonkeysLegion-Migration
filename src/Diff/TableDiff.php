<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Diff;

use MonkeysLegion\Migration\Schema\ColumnDefinition;
use MonkeysLegion\Migration\Schema\ForeignKeyDefinition;
use MonkeysLegion\Migration\Schema\IndexDefinition;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Diff for a single table — what columns, indexes, and FKs to
 * add, modify, or drop.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class TableDiff
{
    public function __construct(
        public readonly string $tableName,

        /** @var list<ColumnDefinition> Columns to add. */
        public array $addedColumns = [],

        /** @var list<ColumnChange> Columns to modify. */
        public array $modifiedColumns = [],

        /** @var list<string> Column names to drop. */
        public array $droppedColumns = [],

        /** @var list<IndexDefinition> Indexes to create. */
        public array $addedIndexes = [],

        /** @var list<string> Index names to drop. */
        public array $droppedIndexes = [],

        /** @var list<ForeignKeyDefinition> Foreign keys to add. */
        public array $addedForeignKeys = [],

        /** @var list<string> FK constraint names to drop. */
        public array $droppedForeignKeys = [],
    ) {}

    /**
     * Whether this table diff has any changes at all.
     */
    public function isEmpty(): bool
    {
        return $this->addedColumns === []
            && $this->modifiedColumns === []
            && $this->droppedColumns === []
            && $this->addedIndexes === []
            && $this->droppedIndexes === []
            && $this->addedForeignKeys === []
            && $this->droppedForeignKeys === [];
    }

    /**
     * Human-readable summary of changes.
     */
    public function describe(): string
    {
        $parts = [];

        if ($this->addedColumns) {
            $names = array_map(fn(ColumnDefinition $c): string => $c->name, $this->addedColumns);
            $parts[] = 'add columns: ' . implode(', ', $names);
        }

        if ($this->modifiedColumns) {
            $names = array_map(fn(ColumnChange $c): string => $c->columnName, $this->modifiedColumns);
            $parts[] = 'modify columns: ' . implode(', ', $names);
        }

        if ($this->droppedColumns) {
            $parts[] = 'drop columns: ' . implode(', ', $this->droppedColumns);
        }

        if ($this->addedIndexes) {
            $names = array_map(fn(IndexDefinition $i): string => $i->name, $this->addedIndexes);
            $parts[] = 'add indexes: ' . implode(', ', $names);
        }

        if ($this->droppedIndexes) {
            $parts[] = 'drop indexes: ' . implode(', ', $this->droppedIndexes);
        }

        if ($this->addedForeignKeys) {
            $names = array_map(fn(ForeignKeyDefinition $f): string => $f->name, $this->addedForeignKeys);
            $parts[] = 'add FKs: ' . implode(', ', $names);
        }

        if ($this->droppedForeignKeys) {
            $parts[] = 'drop FKs: ' . implode(', ', $this->droppedForeignKeys);
        }

        return implode('; ', $parts) ?: 'no changes';
    }
}
