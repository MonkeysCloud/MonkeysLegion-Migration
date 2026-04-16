<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration;

use DateTimeImmutable;
use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Migration\Dialect\MySqlDialect;
use MonkeysLegion\Migration\Dialect\PostgreSqlDialect;
use MonkeysLegion\Migration\Dialect\SqlDialect;
use MonkeysLegion\Migration\Dialect\SqliteDialect;
use MonkeysLegion\Migration\Diff\DiffPlan;
use MonkeysLegion\Migration\Diff\SchemaDiffer;
use MonkeysLegion\Migration\Renderer\SqlRenderer;
use MonkeysLegion\Migration\Schema\EntitySchemaBuilder;
use MonkeysLegion\Migration\Schema\SchemaIntrospector;
use PDO;
use ReflectionClass;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Facade that orchestrates entity-schema diffing and migration generation.
 *
 * In v2, this class delegates to:
 *  - EntitySchemaBuilder  — reads entity attributes → TableDefinition
 *  - SchemaIntrospector   — reads live DB → TableDefinition
 *  - SchemaDiffer         — compares two states → DiffPlan
 *  - SqlRenderer          — converts DiffPlan → SQL
 *
 * Backward-compatible: the public `diff()` and `generate()` methods
 * retain the same signatures as v1 so existing CLI commands keep working.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class MigrationGenerator
{
    /** @var list<string> Tables that must never be dropped automatically. */
    private array $protectedTables = ['migrations', 'ml_migrations'];

    /** Detected PDO driver name. */
    private readonly string $driver;

    /** Dialect strategy resolved from the driver. */
    private readonly SqlDialect $dialect;

    /** v2 components */
    private readonly EntitySchemaBuilder $entityBuilder;
    private readonly SchemaIntrospector $introspector;
    private readonly SchemaDiffer $differ;
    private readonly SqlRenderer $renderer;

    public function __construct(private readonly ConnectionInterface $db)
    {
        $this->driver = $this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

        $this->dialect = match ($this->driver) {
            'pgsql'  => new PostgreSqlDialect(),
            'sqlite' => new SqliteDialect(),
            'mysql'  => new MySqlDialect(),
            default  => throw new \RuntimeException(
                sprintf(
                    "Unsupported PDO driver '%s'. Supported: 'mysql', 'pgsql', 'sqlite'.",
                    $this->driver,
                ),
            ),
        };

        $this->entityBuilder = new EntitySchemaBuilder();
        $this->introspector  = new SchemaIntrospector($this->db, $this->dialect);
        $this->differ        = new SchemaDiffer();
        $this->renderer      = new SqlRenderer($this->dialect);

        // Register protected tables
        foreach ($this->protectedTables as $table) {
            $this->differ->addProtectedTable($table);
        }
    }

    // ── Public API ─────────────────────────────────────────────────

    /**
     * Compute SQL to migrate current DB schema to match entity metadata.
     *
     * Backward-compatible with v1 signature:
     *   $sql = $generator->diff($entities, $schema);
     *
     * In v2, $schema can be:
     *  - array<string, array<string, array<string, mixed>>>  (v1 raw format)
     *  - array<string, TableDefinition>                       (v2 structured)
     *
     * @param list<class-string|ReflectionClass<object>> $entities Entity FQCNs.
     * @param array<string, mixed>                        $schema   Current DB schema.
     *
     * @return string SQL statements to apply.
     */
    public function diff(array $entities, array $schema): string
    {
        $plan = $this->computeDiff($entities, $schema);

        if ($plan->isEmpty()) {
            return '';
        }

        return $this->renderer->render($plan);
    }

    /**
     * Compute a structured DiffPlan (v2 API).
     *
     * @param list<class-string|ReflectionClass<object>> $entities
     * @param array<string, mixed>|null                   $schema  Current DB schema (null = introspect).
     */
    public function computeDiff(array $entities, ?array $schema = null): DiffPlan
    {
        // Build desired state from entities
        $desiredTables = $this->entityBuilder->buildAll($entities);
        $joinTables    = $this->entityBuilder->buildJoinTables($entities);
        $desired       = array_merge($desiredTables, $joinTables);

        // Build current state
        if ($schema === null) {
            $current = $this->introspector->introspect();
        } else {
            // Convert v1 raw format to v2 TableDefinitions if needed
            $current = $this->normalizeSchema($schema);
        }

        return $this->differ->diff($desired, $current);
    }

    /**
     * Generate a migration PHP file under var/migrations/.
     *
     * @param list<class-string|ReflectionClass<object>> $entities
     * @param array<string, mixed>                        $schema
     * @param string                                      $name
     *
     * @return string Path to the generated migration file.
     */
    public function generate(array $entities, array $schema, string $name = 'migration'): string
    {
        $plan = $this->computeDiff($entities, $schema);

        $sqlUp   = $plan->isEmpty() ? '' : trim($this->renderer->render($plan));
        $sqlDown = $plan->isEmpty() ? '' : trim($this->renderer->renderReverse($plan));

        $ts    = (new DateTimeImmutable())->format('YmdHis');
        $slug  = preg_replace('/[^A-Za-z0-9]+/', '_', $name);
        $class = "M{$ts}" . ucfirst((string) $slug);
        $file  = base_path("var/migrations/{$class}.php");

        $template = <<<PHP
<?php
declare(strict_types=1);

namespace App\Migration;

use MonkeysLegion\Database\Contracts\ConnectionInterface;

final class {$class}
{
    public function up(ConnectionInterface \$db): void
    {
        \$db->pdo()->exec(<<<'SQL'
{$sqlUp}
SQL);
    }

    public function down(ConnectionInterface \$db): void
    {
        \$db->pdo()->exec(<<<'SQL'
{$sqlDown}
SQL);
    }
}
PHP;

        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($file, $template);

        return $file;
    }

    /**
     * Generate a backup SQL file of the current schema.
     *
     * @return string Path to the backup file.
     */
    public function backup(): string
    {
        $schema    = $this->introspector->introspect();
        $timestamp = (new DateTimeImmutable())->format('YmdHis');
        $file      = base_path("var/backups/schema_{$timestamp}.sql");

        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $lines = ["-- Schema backup: {$timestamp}", "-- Driver: {$this->driver}", ''];

        foreach ($schema as $tableName => $table) {
            $lines[] = "-- Table: {$tableName}";
            $lines[] = "-- Columns: " . implode(', ', $table->getColumnNames());
            $lines[] = '';
        }

        file_put_contents($file, implode("\n", $lines));

        return $file;
    }

    // ── Accessors for v2 components ───────────────────────────────

    /**
     * Get the entity schema builder.
     */
    public function getEntityBuilder(): EntitySchemaBuilder
    {
        return $this->entityBuilder;
    }

    /**
     * Get the schema introspector.
     */
    public function getIntrospector(): SchemaIntrospector
    {
        return $this->introspector;
    }

    /**
     * Get the schema differ.
     */
    public function getDiffer(): SchemaDiffer
    {
        return $this->differ;
    }

    /**
     * Get the SQL renderer.
     */
    public function getRenderer(): SqlRenderer
    {
        return $this->renderer;
    }

    /**
     * Get the dialect.
     */
    public function getDialect(): SqlDialect
    {
        return $this->dialect;
    }

    // ── Private helpers ────────────────────────────────────────────

    /**
     * Normalize a v1 raw schema format to v2 TableDefinitions.
     *
     * v1 format: [ tableName => [ colName => [ 'Field' => ..., 'Type' => ... ] ] ]
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, \MonkeysLegion\Migration\Schema\TableDefinition>
     */
    private function normalizeSchema(array $schema): array
    {
        $tables = [];

        foreach ($schema as $tableName => $cols) {
            // Check if this is already a TableDefinition (v2 format)
            if ($cols instanceof \MonkeysLegion\Migration\Schema\TableDefinition) {
                $tables[$tableName] = $cols;
                continue;
            }

            if (!is_array($cols)) {
                continue;
            }

            $columns = [];
            foreach ($cols as $colName => $colMeta) {
                if (!is_string($colName) || !is_array($colMeta)) {
                    continue;
                }

                $type = strtolower(
                    (string) ($colMeta['Type'] ?? $colMeta['type'] ?? $colMeta['DATA_TYPE'] ?? 'varchar'),
                );

                // Normalize nullable from multiple possible key formats
                $nullableRaw = $colMeta['nullable'] ?? $colMeta['Null'] ?? $colMeta['is_nullable'] ?? false;
                $nullable = is_bool($nullableRaw)
                    ? $nullableRaw
                    : in_array(strtoupper((string) $nullableRaw), ['YES', 'TRUE', '1'], true);

                $columns[$colName] = new \MonkeysLegion\Migration\Schema\ColumnDefinition(
                    name:     $colName,
                    type:     $type,
                    nullable: $nullable,
                    default:  $colMeta['Default'] ?? $colMeta['column_default'] ?? null,
                );
            }

            $tables[$tableName] = new \MonkeysLegion\Migration\Schema\TableDefinition(
                name:    $tableName,
                columns: $columns,
            );
        }

        return $tables;
    }
}
