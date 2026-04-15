<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Schema;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Immutable value object representing a database index.
 *
 * Supports single-column, composite, unique, and type-specific indexes
 * (BTREE, HASH, GIN, GIST, FULLTEXT).
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class IndexDefinition
{
    /**
     * @param string       $name    Index name (e.g. 'idx_users_email').
     * @param list<string> $columns Column names included in the index.
     * @param bool         $unique  Whether this is a UNIQUE index.
     * @param string|null  $type    Index type: 'BTREE', 'HASH', 'GIN', 'GIST', 'FULLTEXT'.
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique = false,
        public ?string $type = null,
    ) {}

    /**
     * Generate a default index name from table and columns.
     *
     * @param string       $table   Table name.
     * @param list<string> $columns Column names.
     * @param bool         $unique  Whether this is a unique index.
     *
     * @return string Generated name like 'idx_users_email' or 'uniq_users_email'.
     */
    public static function generateName(string $table, array $columns, bool $unique = false): string
    {
        $prefix = $unique ? 'uniq' : 'idx';
        $suffix = implode('_', $columns);

        // Truncate to 64 chars (MySQL identifier limit)
        $name = "{$prefix}_{$table}_{$suffix}";

        return strlen($name) > 64
            ? substr($name, 0, 56) . '_' . substr(md5($name), 0, 7)
            : $name;
    }
}
