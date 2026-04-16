<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Runner;

use MonkeysLegion\Database\Contracts\ConnectionInterface;
use PDO;
use RuntimeException;
use Throwable;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Executes migration files with batch tracking, rollback support,
 * and transactional safety (where the dialect supports it).
 *
 * Covers functionality from:
 *  - Laravel: migrate, migrate:rollback, migrate:fresh, migrate:refresh
 *  - Doctrine: migrations:execute, migrations:status
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class MigrationRunner
{
    private readonly string $driver;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly BatchTracker $tracker,
    ) {
        $this->driver = $this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    // ── Public API ─────────────────────────────────────────────────

    /**
     * Run all pending migrations.
     *
     * @param string   $migrationsDir Absolute path to migrations directory.
     * @param int|null $steps         Max number of migrations to run (null = all).
     */
    public function run(string $migrationsDir, ?int $steps = null): RunResult
    {
        $start = microtime(true);

        $this->tracker->ensureTable();

        $allFiles = $this->scanMigrations($migrationsDir);
        $pending  = $this->tracker->getPending($allFiles);

        if ($pending === []) {
            return new RunResult(durationMs: $this->elapsed($start));
        }

        if ($steps !== null) {
            $pending = array_slice($pending, 0, $steps);
        }

        $batch    = $this->tracker->getLastBatch() + 1;
        $executed = [];
        $failed   = [];

        foreach ($pending as $file) {
            try {
                $this->executeMigration($migrationsDir, $file, 'up');
                $this->tracker->recordMigration($file, $batch);
                $executed[] = $file;
            } catch (Throwable $e) {
                $failed[] = $file;

                return new RunResult(
                    executed:   $executed,
                    failed:     $failed,
                    durationMs: $this->elapsed($start),
                    error:      "{$file}: {$e->getMessage()}",
                );
            }
        }

        return new RunResult(
            executed:   $executed,
            durationMs: $this->elapsed($start),
        );
    }

    /**
     * Rollback migrations.
     *
     * @param int|null $steps Number of individual migrations to rollback (null = last batch).
     * @param int|null $batch Specific batch number to rollback.
     */
    public function rollback(?int $steps = null, ?int $batch = null): RunResult
    {
        $start = microtime(true);

        $this->tracker->ensureTable();

        if ($batch !== null) {
            $pdo  = $this->db->pdo();
            $stmt = $pdo->prepare(
                'SELECT migration FROM ml_migrations WHERE batch = :b ORDER BY id DESC',
            );
            $stmt->execute(['b' => $batch]);
            $migrations = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } elseif ($steps !== null) {
            $migrations = $this->tracker->getLastStepsMigrations($steps);
        } else {
            $migrations = $this->tracker->getLastBatchMigrations();
        }

        if ($migrations === []) {
            return new RunResult(durationMs: $this->elapsed($start));
        }

        $executed = [];
        $failed   = [];

        // Determine migration directory from existing files
        $migDir = $this->guessMigrationDir();

        foreach ($migrations as $file) {
            try {
                $this->executeMigration($migDir, $file, 'down');
                $this->tracker->removeMigration($file);
                $executed[] = $file;
            } catch (Throwable $e) {
                $failed[] = $file;

                return new RunResult(
                    executed:   $executed,
                    failed:     $failed,
                    durationMs: $this->elapsed($start),
                    error:      "{$file}: {$e->getMessage()}",
                );
            }
        }

        return new RunResult(
            executed:   $executed,
            durationMs: $this->elapsed($start),
        );
    }

    /**
     * Reset: rollback all migrations.
     */
    public function reset(): RunResult
    {
        $start = microtime(true);

        $this->tracker->ensureTable();

        $all      = array_reverse($this->tracker->getExecutedNames());
        $executed = [];
        $failed   = [];
        $migDir   = $this->guessMigrationDir();

        foreach ($all as $file) {
            try {
                $this->executeMigration($migDir, $file, 'down');
                $this->tracker->removeMigration($file);
                $executed[] = $file;
            } catch (Throwable $e) {
                $failed[] = $file;

                return new RunResult(
                    executed:   $executed,
                    failed:     $failed,
                    durationMs: $this->elapsed($start),
                    error:      "{$file}: {$e->getMessage()}",
                );
            }
        }

        return new RunResult(
            executed:   $executed,
            durationMs: $this->elapsed($start),
        );
    }

    /**
     * Refresh: rollback all then re-run all migrations.
     */
    public function refresh(string $migrationsDir): RunResult
    {
        $resetResult = $this->reset();

        if (!$resetResult->success) {
            return $resetResult;
        }

        return $this->run($migrationsDir);
    }

    /**
     * Fresh: drop ALL tables then re-run all migrations.
     */
    public function fresh(string $migrationsDir): RunResult
    {
        $start = microtime(true);

        $this->dropAllTables();

        $runResult = $this->run($migrationsDir);

        return new RunResult(
            executed:   $runResult->executed,
            failed:     $runResult->failed,
            durationMs: $this->elapsed($start),
            error:      $runResult->error,
        );
    }

    /**
     * Get migration status for all available files.
     *
     * @param string $migrationsDir Absolute path to migrations directory.
     *
     * @return list<MigrationStatus>
     */
    public function status(string $migrationsDir): array
    {
        $this->tracker->ensureTable();
        $allFiles = $this->scanMigrations($migrationsDir);

        return $this->tracker->getStatus($allFiles);
    }

    /**
     * Dry-run: return SQL that would be executed without actually running.
     *
     * @param string $migrationsDir Absolute path to migrations directory.
     *
     * @return list<string> SQL statements that would be executed.
     */
    public function pretend(string $migrationsDir): array
    {
        $this->tracker->ensureTable();

        $allFiles = $this->scanMigrations($migrationsDir);
        $pending  = $this->tracker->getPending($allFiles);
        $stmts    = [];

        foreach ($pending as $file) {
            $stmts[] = "-- Migration: {$file}";

            $path = rtrim($migrationsDir, '/') . '/' . $file;
            if (is_file($path)) {
                $content = file_get_contents($path);
                if ($content !== false) {
                    // Extract SQL from up() method (basic heuristic)
                    if (preg_match("/exec\(<<<'SQL'\s*\n(.+?)\nSQL\)/s", $content, $m)) {
                        $stmts[] = trim($m[1]);
                    }
                }
            }
        }

        return $stmts;
    }

    // ── Private helpers ────────────────────────────────────────────

    /**
     * Scan migration files from a directory, sorted alphabetically.
     *
     * @return list<string> Filenames (without path).
     */
    private function scanMigrations(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.php');

        if ($files === false) {
            return [];
        }

        $names = array_map('basename', $files);
        sort($names);

        return $names;
    }

    /**
     * Execute a migration file's up() or down() method.
     */
    private function executeMigration(string $dir, string $file, string $direction): void
    {
        $path = rtrim($dir, '/') . '/' . $file;

        if (!is_file($path)) {
            throw new RuntimeException("Migration file not found: {$path}");
        }

        require_once $path;

        // Extract class name from filename (convention: M20260101_Name.php → M20260101_Name)
        $class = 'App\\Migration\\' . pathinfo($file, PATHINFO_FILENAME);

        if (!class_exists($class)) {
            // Try without namespace
            $class = pathinfo($file, PATHINFO_FILENAME);
        }

        if (!class_exists($class)) {
            throw new RuntimeException("Migration class not found in {$file}");
        }

        $instance = new $class();

        if (!method_exists($instance, $direction)) {
            throw new RuntimeException(
                "Migration {$class} does not have a {$direction}() method.",
            );
        }

        // Use transactional DDL where the dialect supports it.
        // PostgreSQL and SQLite both support transactional DDL;
        // MySQL/MariaDB do not (DDL causes implicit commit).
        if (in_array($this->driver, ['pgsql', 'sqlite'], true)) {
            $this->db->transaction(function () use ($instance, $direction): void {
                $instance->{$direction}($this->db);
            });
        } else {
            $instance->{$direction}($this->db);
        }
    }

    /**
     * Drop all tables in the database.
     */
    private function dropAllTables(): void
    {
        $pdo = $this->db->pdo();

        match ($this->driver) {
            'pgsql'  => $this->dropAllTablesPgsql($pdo),
            'sqlite' => $this->dropAllTablesSqlite($pdo),
            default  => $this->dropAllTablesMysql($pdo),
        };
    }

    private function dropAllTablesMysql(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        $stmt   = $pdo->query('SHOW TABLES');
        $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function dropAllTablesPgsql(PDO $pdo): void
    {
        $stmt   = $pdo->query(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public'",
        );
        $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS \"{$table}\" CASCADE");
        }
    }

    private function dropAllTablesSqlite(PDO $pdo): void
    {
        $stmt   = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'",
        );
        $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS \"{$table}\"");
        }
    }

    /**
     * Guess the migration directory from base_path.
     */
    private function guessMigrationDir(): string
    {
        if (function_exists('base_path')) {
            return base_path('var/migrations');
        }

        return getcwd() . '/var/migrations';
    }

    /**
     * Calculate elapsed milliseconds.
     */
    private function elapsed(float $start): float
    {
        return round((microtime(true) - $start) * 1000, 2);
    }
}
