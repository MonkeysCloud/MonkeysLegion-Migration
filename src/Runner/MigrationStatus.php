<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Runner;

use DateTimeImmutable;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Status of a single migration file.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class MigrationStatus
{
    public function __construct(
        public readonly string $name,
        public readonly bool $ran,
        public readonly ?int $batch = null,
        public readonly ?DateTimeImmutable $executedAt = null,
    ) {}

    /**
     * Human-readable status label.
     */
    public string $label {
        get => $this->ran ? "Ran (batch {$this->batch})" : 'Pending';
    }
}
