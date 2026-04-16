<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Schema;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Immutable value object representing a foreign key constraint.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class ForeignKeyDefinition
{
    /**
     * @param string $name             Constraint name (e.g. 'fk_posts_user_id').
     * @param string $column           Local column name.
     * @param string $referencedTable  Referenced table name.
     * @param string $referencedColumn Referenced column name.
     * @param string $onDelete         ON DELETE action: RESTRICT|CASCADE|SET NULL|NO ACTION.
     * @param string $onUpdate         ON UPDATE action: RESTRICT|CASCADE|SET NULL|NO ACTION.
     */
    public function __construct(
        public string $name,
        public string $column,
        public string $referencedTable,
        public string $referencedColumn,
        public string $onDelete = 'RESTRICT',
        public string $onUpdate = 'RESTRICT',
    ) {}

    /**
     * Generate a default FK constraint name.
     *
     * @param string $table  Local table name.
     * @param string $column Local column name.
     *
     * @return string Generated name like 'fk_posts_user_id'.
     */
    public static function generateName(string $table, string $column): string
    {
        $name = "fk_{$table}_{$column}";

        return strlen($name) > 64
            ? substr($name, 0, 56) . '_' . substr(md5($name), 0, 7)
            : $name;
    }
}
