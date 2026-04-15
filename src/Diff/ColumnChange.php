<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Diff;

use MonkeysLegion\Migration\Schema\ColumnDefinition;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Represents a change to an existing column (type, nullability, default, etc.).
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class ColumnChange
{
    public function __construct(
        public string $columnName,
        public ColumnDefinition $from,
        public ColumnDefinition $to,
    ) {}

    /**
     * Human-readable description of what changed.
     */
    public function describe(): string
    {
        $changes = [];

        if ($this->from->type !== $this->to->type) {
            $changes[] = "type: {$this->from->type} → {$this->to->type}";
        }

        if ($this->from->nullable !== $this->to->nullable) {
            $changes[] = $this->to->nullable ? 'nullable → true' : 'nullable → false';
        }

        if ($this->from->default !== $this->to->default) {
            $fromDef = $this->from->default === null ? 'NULL' : (string) $this->from->default;
            $toDef   = $this->to->default === null ? 'NULL' : (string) $this->to->default;
            $changes[] = "default: {$fromDef} → {$toDef}";
        }

        if ($this->from->length !== $this->to->length) {
            $changes[] = "length: {$this->from->length} → {$this->to->length}";
        }

        return implode(', ', $changes) ?: 'no visible change';
    }
}
