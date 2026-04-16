<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Runner;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Result of a migration run (up, down, refresh, etc.).
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class RunResult
{
    /**
     * @param list<string>  $executed   Successfully executed migration names.
     * @param list<string>  $failed     Failed migration names.
     * @param float         $durationMs Total duration in milliseconds.
     * @param string|null   $error      Error message if any.
     */
    public function __construct(
        public readonly array $executed = [],
        public readonly array $failed = [],
        public readonly float $durationMs = 0.0,
        public readonly ?string $error = null,
    ) {}

    /**
     * Whether the run was fully successful.
     */
    public bool $success {
        get => $this->failed === [] && $this->error === null;
    }

    /**
     * Total number of migrations processed.
     */
    public int $total {
        get => count($this->executed) + count($this->failed);
    }
}
