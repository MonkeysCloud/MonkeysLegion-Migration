<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Schema;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Immutable value object representing a single database column definition.
 *
 * Used by both EntitySchemaBuilder (desired state) and SchemaIntrospector
 * (current state) so that SchemaDiffer can compare apples-to-apples.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class ColumnDefinition
{
    /**
     * @param string          $name          Column name.
     * @param string          $type          Logical type (e.g. 'string', 'int', 'uuid').
     * @param int|string|null $length        Length or precision spec (e.g. 255, '10,2').
     * @param bool            $nullable      Whether the column allows NULL.
     * @param bool            $autoIncrement Whether the column auto-increments.
     * @param bool            $primaryKey    Whether this column is the primary key.
     * @param mixed           $default       Default value (null = no default).
     * @param list<string>|null $enumValues  Allowed values for ENUM/SET types.
     * @param bool            $unsigned      Whether the column is unsigned (integer types).
     * @param bool            $unique        Whether a UNIQUE constraint exists.
     * @param string|null     $comment       Optional column comment.
     * @param string|null     $columnName    Actual DB column name if different from $name.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly int|string|null $length = null,
        public readonly bool $nullable = false,
        public readonly bool $autoIncrement = false,
        public readonly bool $primaryKey = false,
        public readonly mixed $default = null,
        public readonly ?array $enumValues = null,
        public readonly bool $unsigned = false,
        public readonly bool $unique = false,
        public readonly ?string $comment = null,
        public readonly ?string $columnName = null,
    ) {}

    /**
     * Get the effective database column name.
     *
     * Returns $columnName if explicitly set (via #[Column] attribute),
     * otherwise falls back to $name (property name).
     */
    public string $effectiveName {
        get => $this->columnName ?? $this->name;
    }

    /**
     * Create a copy with modified properties.
     *
     * @param array<string, mixed> $overrides Property name => new value.
     *
     * @return self
     */
    public function with(array $overrides): self
    {
        return new self(
            name:          $overrides['name'] ?? $this->name,
            type:          $overrides['type'] ?? $this->type,
            length:        $overrides['length'] ?? $this->length,
            nullable:      $overrides['nullable'] ?? $this->nullable,
            autoIncrement: $overrides['autoIncrement'] ?? $this->autoIncrement,
            primaryKey:    $overrides['primaryKey'] ?? $this->primaryKey,
            default:       array_key_exists('default', $overrides) ? $overrides['default'] : $this->default,
            enumValues:    $overrides['enumValues'] ?? $this->enumValues,
            unsigned:      $overrides['unsigned'] ?? $this->unsigned,
            unique:        $overrides['unique'] ?? $this->unique,
            comment:       $overrides['comment'] ?? $this->comment,
            columnName:    $overrides['columnName'] ?? $this->columnName,
        );
    }
}
