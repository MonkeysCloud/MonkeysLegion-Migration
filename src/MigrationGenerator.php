<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration;

use DateTimeImmutable;
use MonkeysLegion\Database\MySQL\Connection;
use MonkeysLegion\Entity\Attributes\Column as ColumnAttr;
use MonkeysLegion\Entity\Attributes\Field as FieldAttr;
use MonkeysLegion\Entity\Attributes\JoinTable;
use MonkeysLegion\Entity\Attributes\ManyToMany;
use MonkeysLegion\Entity\Attributes\ManyToOne;
use MonkeysLegion\Entity\Attributes\OneToMany;
use MonkeysLegion\Entity\Attributes\OneToOne;
use ReflectionClass;
use ReflectionProperty;

final class MigrationGenerator
{
    public function __construct(private Connection $db) {}

    /**
     * Generate a migration PHP file under var/migrations/.
     */
    public function generate(array $entities, array $schema, string $name = 'migration'): string
    {
        $sqlUp   = trim($this->diff($entities, $schema));
        $sqlDown = $this->autoReverse($sqlUp);

        $ts     = (new DateTimeImmutable())->format('YmdHis');
        $slug   = preg_replace('/[^A-Za-z0-9]+/', '_', $name);
        $class  = "M{$ts}" . ucfirst($slug);
        $file   = base_path("var/migrations/{$class}.php");

        $template = <<<PHP
<?php
declare(strict_types=1);

namespace App\Migration;

use MonkeysLegion\Database\MySQL\Connection;

final class {$class}
{
    public function up(Connection \$db): void
    {
        \$db->pdo()->exec(<<<'SQL'
{$sqlUp}
SQL);
    }

    public function down(Connection \$db): void
    {
        \$db->pdo()->exec(<<<'SQL'
{$sqlDown}
SQL);
    }
}
PHP;

        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, $template);

        return $file;
    }

    /**
     * Compute SQL to migrate current DB schema to match entity metadata.
     * Returns a complete SQL string ending with a semicolon.
     */
    public function diff(array $entities, array $schema): string
    {
        $alterStmts     = [];
        $joinTableStmts = [];

        foreach ($entities as $entityFqcn) {
            $ref   = $entityFqcn instanceof ReflectionClass ? $entityFqcn : new ReflectionClass($entityFqcn);
            $table = strtolower($ref->getShortName());

            /* 1️⃣  Table doesn’t exist → full CREATE */
            if (!isset($schema[$table])) {
                $alterStmts[] = $this->createTableSql($ref, $table);
                // still gather join tables so foreign keys reference existing tables later
            }

            $existingCols = array_keys($schema[$table] ?? []);
            $skipCols     = []; // columns to ignore because they represent ManyToMany relations

            foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {

                /* ─── Many-to-Many (owning side) → create join-table, never a column */
                foreach ($prop->getAttributes(ManyToMany::class) as $attr) {
                    /** @var ManyToMany $meta */
                    $meta = $attr->newInstance();

                    // Owning side = has joinTable metadata
                    if ($meta->joinTable instanceof JoinTable) {
                        $jt = $meta->joinTable;
                        $joinTableStmts[$jt->name] = <<<SQL
CREATE TABLE IF NOT EXISTS `{$jt->name}` (
    `{$jt->joinColumn}` INT NOT NULL,
    `{$jt->inverseColumn}` INT NOT NULL,
    PRIMARY KEY (`{$jt->joinColumn}`, `{$jt->inverseColumn}`),
    FOREIGN KEY (`{$jt->joinColumn}`)   REFERENCES `{$table}`(`id`)   ON DELETE CASCADE,
    FOREIGN KEY (`{$jt->inverseColumn}`) REFERENCES `{$this->snake($meta->targetEntity ?? $prop->getType()?->getName() ?? '')}`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
                    }
                    // mark property so we don't add a column for it
                    $skipCols[] = $prop->getName();
                }

                /* ─── Any other relation (1-1 / 1-N / N-1) → also no column */
                if ($this->isRelation($prop)) {
                    $skipCols[] = $prop->getName();
                }

                /* ─── Scalar / Field columns ───────────────────────── */
                foreach ($prop->getAttributes(FieldAttr::class) as $attr) {
                    $field = $attr->newInstance();
                    $col   = $prop->getName();
                    if (in_array($col, $skipCols, true)) {
                        continue; // relation property – skip
                    }
                    if (!in_array($col, $existingCols, true)) {
                        $type = $this->mapToSql($field->type ?? 'string', $field->length ?? null);
                        $alterStmts[] = "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$type}";
                    }
                }

                /* ─── Column overrides via #[Column] ──────────────── */
                if ($prop->getAttributes(ColumnAttr::class)) {
                    $attr = $prop->getAttributes(ColumnAttr::class)[0]->newInstance();
                    $col  = $prop->getName();
                    if (in_array($col, $skipCols, true)) {
                        continue;
                    }
                    if (!in_array($col, $existingCols, true)) {
                        $sqlType = strtoupper($attr->type ?? 'VARCHAR(255)');
                        $length  = $attr->length ? "({$attr->length})" : '';
                        $null    = $attr->nullable ? ' NULL' : ' NOT NULL';
                        $alterStmts[] = "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$sqlType}{$length}{$null}";
                    }
                }
            }
        }

        $sql = implode(";\n", $alterStmts);
        if ($joinTableStmts) {
            $sql .= ($sql ? ";\n\n" : '') . implode("\n", $joinTableStmts);
        }
        return rtrim($sql, ";\n") . ';';
    }

    /**
     * Map a PHP type to a SQL type.
     * Defaults to VARCHAR(255) if no length is specified.
     */
    private function mapToSql(string $dbType, ?int $length = null): string
    {
        return match (strtolower($dbType)) {
            'int','integer'   => 'INT NOT NULL',
            'float','double'  => 'DOUBLE NOT NULL',
            'bool','boolean'  => 'TINYINT(1) NOT NULL',
            'text'            => 'TEXT NOT NULL',
            default           => 'VARCHAR(' . ($length ?? 255) . ') NOT NULL',
        };
    }

    /**
     * Create a SQL CREATE TABLE statement for the given entity class.
     * Assumes the class has a public 'id' property.
     */
    private function createTableSql(ReflectionClass $ref, string $table): string
    {
        $cols = $this->getColumnDefinitions($ref);
        $defs = implode(",\n  ", $cols);
        return <<<SQL
CREATE TABLE `{$table}` (
  {$defs},
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    }

    /**
     * Extract scalar column definitions.  Skips ManyToMany properties.
     */
    private function getColumnDefinitions(ReflectionClass $ref): array
    {
        $defs = [];

        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();

            // skip join-table collections
            if ($this->isRelation($prop)) {
                continue;
            }

            if ($name === 'id') {
                $defs[$name] = "`id` INT NOT NULL AUTO_INCREMENT";
                continue;
            }

            $type    = $prop->getType()?->getName() ?? 'string';
            $sqlType = $this->mapToSql($type);

            if ($prop->getAttributes(ColumnAttr::class)) {
                /** @var ColumnAttr $attr */
                $attr     = $prop->getAttributes(ColumnAttr::class)[0]->newInstance();
                $sqlType  = strtoupper($attr->type   ?? $sqlType);
                $length   = $attr->length            ? "({$attr->length})" : '';
                $nullable = $attr->nullable          ? ' NULL' : ' NOT NULL';
                $defs[$name] = "`{$name}` {$sqlType}{$length}{$nullable}";
            } else {
                $defs[$name] = "`{$name}` {$sqlType}";
            }
        }

        return $defs;
    }

    /**
     * Generate a reverse migration SQL from the given SQL.
     * This is a very basic implementation that only handles CREATE TABLE and ALTER TABLE ADD COLUMN.
     */
    private function autoReverse(string $sql): string
    {
        $lines = array_filter(array_map('trim', explode("\n", $sql)));
        $out   = [];

        foreach ($lines as $line) {
            if (preg_match('/^CREATE\s+TABLE\s+`?(\w+)`?/i', $line, $m)) {
                $out[] = "DROP TABLE IF EXISTS `{$m[1]}`;";
            } elseif (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+COLUMN\s+`?(\w+)`?/i', $line, $m)) {
                $out[] = "ALTER TABLE `{$m[1]}` DROP COLUMN `{$m[2]}`;";
            } else {
                $out[] = "-- TODO reverse: {$line}";
            }
        }

        return implode("\n", $out);
    }

    /**
     * Convert a fully qualified class name to a snake_case table name.
     * E.g. "App\Entity\User" becomes "user".
     */
    private function snake(string $class): string
    {
        $base = str_contains($class, '\\') ? substr($class, strrpos($class, '\\') + 1) : $class;
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $base));
    }

    /** Is this property a relation attribute? */
    private function isRelation(ReflectionProperty $p): bool
    {
        return $p->getAttributes(OneToOne::class)
            || $p->getAttributes(OneToMany::class)
            || $p->getAttributes(ManyToOne::class)
            || $p->getAttributes(ManyToMany::class);
    }
}
