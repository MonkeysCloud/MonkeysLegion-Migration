<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Runner;

use DateTimeImmutable;
use MonkeysLegion\Database\Contracts\ConnectionInterface;
use PDO;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Tracks which migrations have been executed, in which batch,
 * using the `ml_migrations` table.
 *
 * Similar to Laravel's Migrator repository but lighter weight.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class BatchTracker
{
    private const string TABLE = 'ml_migrations';

    private readonly string $driver;

    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
        $this->driver = $this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    // ── Public API ─────────────────────────────────────────────────

    /**
     * Ensure the migrations tracking table exists.
     */
    public function ensureTable(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec($this->createTableDdl());
    }

    /**
     * Get all executed migrations.
     *
     * @return list<MigrationStatus>
     */
    public function getExecuted(): array
    {
        $this->ensureTable();

        $pdo  = $this->db->pdo();
        $stmt = $pdo->query(
            'SELECT migration, batch, executed_at FROM ' . self::TABLE . ' ORDER BY id ASC',
        );

        if (!$stmt) {
            return [];
        }

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[] = new MigrationStatus(
                name:       (string) $row['migration'],
                ran:        true,
                batch:      (int) $row['batch'],
                executedAt: new DateTimeImmutable((string) $row['executed_at']),
            );
        }

        return $results;
    }

    /**
     * Get executed migration names as a flat list.
     *
     * @return list<string>
     */
    public function getExecutedNames(): array
    {
        return array_map(
            static fn(MigrationStatus $s): string => $s->name,
            $this->getExecuted(),
        );
    }

    /**
     * Get pending migrations (files not yet executed).
     *
     * @param list<string> $allFiles All migration filenames.
     *
     * @return list<string> Files not yet executed.
     */
    public function getPending(array $allFiles): array
    {
        $executed = $this->getExecutedNames();

        return array_values(
            array_filter(
                $allFiles,
                static fn(string $f): bool => !in_array($f, $executed, true),
            ),
        );
    }

    /**
     * Get the last batch number.
     */
    public function getLastBatch(): int
    {
        $this->ensureTable();

        $pdo  = $this->db->pdo();
        $stmt = $pdo->query(
            'SELECT MAX(batch) FROM ' . self::TABLE,
        );

        $result = $stmt ? $stmt->fetchColumn() : false;

        return $result ? (int) $result : 0;
    }

    /**
     * Get migrations from the last batch.
     *
     * @return list<string> Migration names in reverse order.
     */
    public function getLastBatchMigrations(): array
    {
        $lastBatch = $this->getLastBatch();

        if ($lastBatch === 0) {
            return [];
        }

        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT migration FROM ' . self::TABLE
            . ' WHERE batch = :batch ORDER BY id DESC',
        );
        $stmt->execute(['batch' => $lastBatch]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Get migrations from the last N steps.
     *
     * @param int $steps Number of migrations to roll back.
     *
     * @return list<string> Migration names in reverse order.
     */
    public function getLastStepsMigrations(int $steps): array
    {
        $pdo  = $this->db->pdo();
        $stmt = $pdo->query(
            'SELECT migration FROM ' . self::TABLE
            . ' ORDER BY id DESC LIMIT ' . max(1, $steps),
        );

        return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    }

    /**
     * Record a migration as executed.
     */
    public function recordMigration(string $name, int $batch): void
    {
        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (migration, batch, executed_at) VALUES (:m, :b, :e)',
        );
        $stmt->execute([
            'm' => $name,
            'b' => $batch,
            'e' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Remove a migration record (for rollback).
     */
    public function removeMigration(string $name): void
    {
        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE migration = :m',
        );
        $stmt->execute(['m' => $name]);
    }

    /**
     * Remove all migration records (for fresh/reset).
     */
    public function reset(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('DELETE FROM ' . self::TABLE);
    }

    /**
     * Get full migration status (ran + pending).
     *
     * @param list<string> $allFiles All available migration files.
     *
     * @return list<MigrationStatus>
     */
    public function getStatus(array $allFiles): array
    {
        $executed = $this->getExecuted();

        $executedMap = [];
        foreach ($executed as $status) {
            $executedMap[$status->name] = $status;
        }

        $result = [];

        // Add executed first (in order)
        foreach ($executed as $status) {
            $result[] = $status;
        }

        // Add pending
        foreach ($allFiles as $file) {
            if (!isset($executedMap[$file])) {
                $result[] = new MigrationStatus(
                    name: $file,
                    ran:  false,
                );
            }
        }

        return $result;
    }

    // ── Private helpers ────────────────────────────────────────────

    /**
     * DDL for creating the migrations tracking table.
     */
    private function createTableDdl(): string
    {
        return match ($this->driver) {
            'pgsql' => <<<'SQL'
                CREATE TABLE IF NOT EXISTS ml_migrations (
                    id          SERIAL PRIMARY KEY,
                    migration   VARCHAR(255) NOT NULL,
                    batch       INTEGER NOT NULL,
                    executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            SQL,
            'sqlite' => <<<'SQL'
                CREATE TABLE IF NOT EXISTS ml_migrations (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration   TEXT NOT NULL,
                    batch       INTEGER NOT NULL,
                    executed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            SQL,
            default => <<<'SQL'
                CREATE TABLE IF NOT EXISTS ml_migrations (
                    id          INT AUTO_INCREMENT PRIMARY KEY,
                    migration   VARCHAR(255) NOT NULL,
                    batch       INT NOT NULL,
                    executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL,
        };
    }
}
