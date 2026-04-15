<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Schema;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Immutable value object representing a complete database table definition.
 *
 * Aggregates columns, indexes, and foreign keys into a single unit. Used
 * by both EntitySchemaBuilder (desired state from entity classes) and
 * SchemaIntrospector (current state from the live database) so the
 * SchemaDiffer can compare them structurally.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class TableDefinition
{
    /**
     * @param string                          $name        Table name.
     * @param array<string, ColumnDefinition> $columns     Column name => definition.
     * @param string                          $primaryKey  Primary key column name.
     * @param list<IndexDefinition>           $indexes     Index definitions.
     * @param list<ForeignKeyDefinition>      $foreignKeys Foreign key constraints.
     * @param string|null                     $comment     Optional table comment.
     * @param string|null                     $engine      Storage engine (MySQL: InnoDB).
     * @param string|null                     $charset     Character set (MySQL: utf8mb4).
     * @param string|null                     $collation   Collation (MySQL: utf8mb4_unicode_ci).
     */
    public function __construct(
        public string $name,
        public array $columns,
        public string $primaryKey = 'id',
        public array $indexes = [],
        public array $foreignKeys = [],
        public ?string $comment = null,
        public ?string $engine = null,
        public ?string $charset = null,
        public ?string $collation = null,
    ) {}

    /**
     * Check whether a column exists in this table.
     */
    public function hasColumn(string $name): bool
    {
        return isset($this->columns[$name]);
    }

    /**
     * Get a column definition by name, or null if not found.
     */
    public function getColumn(string $name): ?ColumnDefinition
    {
        return $this->columns[$name] ?? null;
    }

    /**
     * Get all column names.
     *
     * @return list<string>
     */
    public function getColumnNames(): array
    {
        return array_keys($this->columns);
    }

    /**
     * Get all index names.
     *
     * @return list<string>
     */
    public function getIndexNames(): array
    {
        return array_map(
            static fn(IndexDefinition $idx): string => $idx->name,
            $this->indexes,
        );
    }

    /**
     * Get all foreign key names.
     *
     * @return list<string>
     */
    public function getForeignKeyNames(): array
    {
        return array_map(
            static fn(ForeignKeyDefinition $fk): string => $fk->name,
            $this->foreignKeys,
        );
    }

    /**
     * Find an index by name, or null if not found.
     */
    public function getIndex(string $name): ?IndexDefinition
    {
        foreach ($this->indexes as $idx) {
            if ($idx->name === $name) {
                return $idx;
            }
        }

        return null;
    }

    /**
     * Find a foreign key by name, or null if not found.
     */
    public function getForeignKey(string $name): ?ForeignKeyDefinition
    {
        foreach ($this->foreignKeys as $fk) {
            if ($fk->name === $name) {
                return $fk;
            }
        }

        return null;
    }
}
