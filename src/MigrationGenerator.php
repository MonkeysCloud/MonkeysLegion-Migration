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
    /** tables that must never be dropped automatically */
    private array $protectedTables = ['migrations'];

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
     * Now honours `$nullable` on ManyToOne / OneToOne owning sides.
     * Returns a complete SQL string ending with a semicolon.
     */
    public function diff(array $entities, array $schema): string
    {
        $alterStmts     = [];
        $joinTableStmts = [];

        // NEW: track which tables should exist after sync
        $seenEntityTables = [];
        $seenJoinTables   = [];

        foreach ($entities as $entityFqcn) {
            $ref   = $entityFqcn instanceof \ReflectionClass ? $entityFqcn : new \ReflectionClass($entityFqcn);
            $table = strtolower($ref->getShortName());

            // track entity table
            $seenEntityTables[$table] = true;

            if (!isset($schema[$table])) {
                $alterStmts[] = $this->createTableSql($ref, $table);
                $schema[$table] = [];
            }

            $existingCols = array_keys($schema[$table] ?? []);
            $skipCols     = [];
            $expectedCols = ['id' => true];

            foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
                $propName = $prop->getName();

                // ManyToMany → remember expected join table
                foreach ($prop->getAttributes(ManyToMany::class) as $attr) {
                    /** @var ManyToMany $meta */
                    $meta = $attr->newInstance();
                    if ($meta->joinTable instanceof JoinTable) {
                        $jt = $meta->joinTable;
                        $seenJoinTables[$jt->name] = true;
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
                    $skipCols[] = $propName;
                }

                // One-to-One inverse side => skip
                foreach ($prop->getAttributes(OneToOne::class) as $a) {
                    $o2o = $a->newInstance();
                    if ($o2o->mappedBy) {
                        $skipCols[] = $propName;
                    }
                }

                // One-to-Many inverse => skip
                if ($prop->getAttributes(OneToMany::class)) {
                    $skipCols[] = $propName;
                }

                // Many-to-One owning side: FK col honours nullable
                if ($prop->getAttributes(ManyToOne::class)) {
                    $m2o   = $prop->getAttributes(ManyToOne::class)[0]->newInstance();
                    $fkCol = $propName . '_id';
                    $expectedCols[$fkCol] = true;

                    if (!in_array($fkCol, $existingCols, true)) {
                        $null = $m2o->nullable ? ' NULL' : ' NOT NULL';
                        $alterStmts[] = "ALTER TABLE `{$table}` ADD COLUMN `{$fkCol}` INT{$null}";
                        $alterStmts[] = "ALTER TABLE `{$table}` ADD CONSTRAINT `fk_{$table}_{$fkCol}` FOREIGN KEY (`{$fkCol}`) REFERENCES `{$this->snake($m2o->targetEntity)}`(`id`)" . ($m2o->nullable ? ' ON DELETE SET NULL' : '');
                    }
                    $skipCols[] = $propName;
                    continue;
                }

                // One-to-One owning side (no mappedBy): FK col honours nullable
                if ($prop->getAttributes(OneToOne::class)) {
                    $o2o = $prop->getAttributes(OneToOne::class)[0]->newInstance();
                    if (!$o2o->mappedBy) {
                        $fkCol = $propName . '_id';
                        $expectedCols[$fkCol] = true;

                        if (!in_array($fkCol, $existingCols, true)) {
                            $null = $o2o->nullable ? ' NULL' : ' NOT NULL';
                            $alterStmts[] = "ALTER TABLE `{$table}` ADD COLUMN `{$fkCol}` INT{$null}";
                            $alterStmts[] = "ALTER TABLE `{$table}` ADD CONSTRAINT `fk_{$table}_{$fkCol}` FOREIGN KEY (`{$fkCol}`) REFERENCES `{$this->snake($o2o->targetEntity)}`(`id`)" . ($o2o->nullable ? ' ON DELETE SET NULL' : '');
                        }
                        $skipCols[] = $propName;
                        continue;
                    }
                }

                // Primary key
                if ($propName === 'id') {
                    $expectedCols['id'] = true;
                    continue;
                }

                // Scalar #[Field]
                foreach ($prop->getAttributes(FieldAttr::class) as $fa) {
                    if (in_array($propName, $skipCols, true)) {
                        continue 2;
                    }
                    $expectedCols[$propName] = true;

                    if (!in_array($propName, $existingCols, true)) {
                        $field = $fa->newInstance();
                        $wantNullable = (bool)($field->nullable ?? false);

                        if (!in_array($propName, $existingCols, true)) {
                            $type = $this->mapToSql($field->type ?? 'string', $field->length ?? null, $wantNullable);
                            $alterStmts[] = "ALTER TABLE `{$table}` ADD COLUMN `{$propName}` {$type}";
                        } else {
                            // Column exists → check & fix nullability/type if needed
                            $colMeta      = $schema[$table][$propName] ?? null; // expects ['nullable'=>bool, 'type'=>'varchar', 'length'=>255] if available
                            $haveNullable = (bool)($colMeta['nullable'] ?? false);

                            // If schema collector doesn’t provide metadata, we can still force a MODIFY safely.
                            if ($haveNullable !== $wantNullable || $colMeta === null) {
                                $base = $this->mapToSqlBase($field->type ?? 'string', $field->length ?? null);
                                $null = $wantNullable ? 'NULL' : 'NOT NULL';
                                $alterStmts[] = "ALTER TABLE `{$table}` MODIFY COLUMN `{$propName}` {$base} {$null}";
                            }
                        }
                    }
                }

                // #[Column] override
                if ($prop->getAttributes(ColumnAttr::class)) {
                    if (in_array($propName, $skipCols, true)) {
                        continue;
                    }
                    $expectedCols[$propName] = true;

                    if (!in_array($propName, $existingCols, true)) {
                        $attr    = $prop->getAttributes(ColumnAttr::class)[0]->newInstance();
                        $sqlType = strtoupper($attr->type ?? 'VARCHAR');
                        $length  = $attr->length ? "({$attr->length})" : '';
                        $null    = $attr->nullable ? ' NULL' : ' NOT NULL';
                        $alterStmts[] = "ALTER TABLE `{$table}` ADD COLUMN `{$propName}` {$sqlType}{$length}{$null}";
                    }
                }
            }

            // ➋ DROP logic: anything in $existingCols that is NOT in $expectedCols should be dropped.
            foreach ($existingCols as $col) {
                if ($col === 'id') continue;
                if (!isset($expectedCols[$col])) {
                    if (str_ends_with($col, '_id')) {
                        $fkName = $this->fkName($table, $col);
                        if ($fkName) {
                            $alterStmts[] = "ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fkName}`";
                        }
                    }
                    $alterStmts[] = "ALTER TABLE `{$table}` DROP COLUMN `{$col}`";
                }
            }
        }

        // ➊ DROP join tables that are not expected
        $dropStmts = [];

        foreach (array_keys($schema) as $tbl) {
            if (in_array($tbl, $this->protectedTables, true)) {
                continue;
            }

            $isEntityExpected = isset($seenEntityTables[$tbl]);
            $isJoinExpected   = isset($seenJoinTables[$tbl]);

            // If table is neither an expected entity table nor an expected join table → drop it
            if (! $isEntityExpected && ! $isJoinExpected) {
                $dropStmts[] = "DROP TABLE IF EXISTS `{$tbl}`";
            }
        }

        // Compose final SQL with FK checks guard if we have drops
        $sql = implode(";\n", $alterStmts);
        if ($joinTableStmts) {
            $sql .= ($sql ? ";\n\n" : '') . implode("\n", $joinTableStmts);
        }
        if ($dropStmts) {
            $drops = implode(";\n", $dropStmts) . ';';
            $guard = "SET FOREIGN_KEY_CHECKS=0;\n{$drops}\nSET FOREIGN_KEY_CHECKS=1;";
            $sql  .= ($sql ? ";\n\n" : '') . $guard;
        }

        return rtrim($sql, ";\n") . ';';
    }

    /**
    * Map a PHP type to a SQL type with optional length and nullability.
     *
     * @param string $dbType The PHP type (e.g. 'string', 'int', 'date').
     * @param int|null $length Optional length for types that require it (e.g. VARCHAR).
     * @param bool $nullable Whether the column should allow NULL values.
     * @return string The SQL type definition.
     */
    private function mapToSql(string $dbType, ?int $length = null, bool $nullable = false): string
    {
        $null = $nullable ? ' NULL' : ' NOT NULL';

        return match (strtolower($dbType)) {
            // Strings
            'string'       => 'VARCHAR(' . ($length ?? 255) . "){$null}",
            'char'         => 'CHAR(' . ($length ?? 1) . "){$null}",
            'text'         => "TEXT{$null}",
            'mediumtext'   => "MEDIUMTEXT{$null}",
            'longtext'     => "LONGTEXT{$null}",

            // Integers
            'integer', 'int'      => "INT{$null}",
            'tinyint'             => "TINYINT" . ($length ? "({$length})" : "(1)") . "{$null}",
            'smallint'            => "SMALLINT{$null}",
            'bigint'              => "BIGINT{$null}",
            'unsignedbigint'      => "BIGINT UNSIGNED{$null}",

            // Decimals & floats
            'decimal'             => 'DECIMAL(' . ($length ?? '10,2') . "){$null}",
            'float'               => "FLOAT{$null}",

            // Boolean
            'boolean'             => "TINYINT(1){$null}",

            // Dates & times
            'date'                => "DATE{$null}",
            'time'                => "TIME{$null}",
            'datetime'            => "DATETIME{$null}",
            'datetimetz'          => "DATETIME{$null}",
            'timestamp'           => "TIMESTAMP{$null}",
            'timestamptz'         => "TIMESTAMP{$null}",
            'year'                => "YEAR{$null}",

            // UUID & binary
            'uuid'                => "CHAR(36){$null}",
            'binary'              => "BLOB{$null}",

            // JSON
            'json'                => "JSON{$null}",
            'simple_json'         => "TEXT{$null}",
            'array'               => "TEXT{$null}",
            'simple_array'        => "TEXT{$null}",

            // Enum & Set
            'enum'                => "ENUM('value1','value2'){ $null }",
            'set'                 => "SET('value1','value2'){ $null }",

            // Spatial
            'geometry'            => "GEOMETRY{$null}",
            'point'               => "POINT{$null}",
            'linestring'          => "LINESTRING{$null}",
            'polygon'             => "POLYGON{$null}",

            // Network
            'ipaddress'           => "VARCHAR(45){$null}",
            'macaddress'          => "VARCHAR(17){$null}",

            // Default
            default               => 'VARCHAR(' . ($length ?? 255) . "){$null}",
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

            // Skip pure inverse relations & Many-to-Many
            if (
                $prop->getAttributes(OneToMany::class)
                || ($prop->getAttributes(OneToOne::class)
                    && $prop->getAttributes(OneToOne::class)[0]->newInstance()->mappedBy)
                || $prop->getAttributes(ManyToMany::class)
            ) {
                continue;
            }

            /* Many-to-One owning side → nullable FK */
            if ($prop->getAttributes(ManyToOne::class)) {
                $m2o      = $prop->getAttributes(ManyToOne::class)[0]->newInstance();
                $colName  = $name . '_id';
                $nullable = $m2o->nullable ? ' NULL' : '';
                $defs[$colName] = "`{$colName}` INT{$nullable}";
                continue;
            }

            /* One-to-One owning side → nullable FK */
            if ($prop->getAttributes(OneToOne::class)) {
                $o2o = $prop->getAttributes(OneToOne::class)[0]->newInstance();
                if (! $o2o->mappedBy) {
                    $colName  = $name . '_id';
                    $nullable = $o2o->nullable ? ' NULL' : '';
                    $defs[$colName] = "`{$colName}` INT{$nullable}";
                    continue;
                }
            }

            /* Primary key */
            if ($name === 'id') {
                $defs[$name] = "`id` INT NOT NULL AUTO_INCREMENT";
                continue;
            }

            /* Scalar field (or #[Column] override) */
            $type      = $prop->getType()?->getName() ?? 'string';
            $nullable  = false;
            if ($prop->getAttributes(FieldAttr::class)) {
                $fa = $prop->getAttributes(FieldAttr::class)[0]->newInstance();
                $type     = $fa->type ?? $type;
                $length   = $fa->length ?? null;
                $nullable = (bool)($fa->nullable ?? false);
                $sqlType  = $this->mapToSql($type, $length, $nullable);
            } else {
                $sqlType  = $this->mapToSql($type, null, false);
            }

            if ($prop->getAttributes(ColumnAttr::class)) {
                /** @var ColumnAttr $attr */
                $attr     = $prop->getAttributes(ColumnAttr::class)[0]->newInstance();
                $sqlType  = strtoupper($attr->type ?? $sqlType);
                $length   = $attr->length   ? "({$attr->length})" : '';
                $nullable = $attr->nullable ? ' NULL' : ' NOT NULL';
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

    /**
     * Map a PHP type to a SQL base type.
     * Defaults to VARCHAR(255) if no length is specified.
     */
    private function mapToSqlBase(string $dbType, ?int $length = null): string
    {
        return match (strtolower($dbType)) {
            'int','integer'        => "INT",
            'float','double'       => "DOUBLE",
            'bool','boolean'       => "TINYINT(1)",
            'text'                 => "TEXT",
            'datetime','timestamp' => "DATETIME",
            'date'                 => "DATE",
            'time'                 => "TIME",
            default                => 'VARCHAR(' . ($length ?? 255) . ')',
        };
    }

    /**
     * Get the name of the foreign key constraint for a given table and column.
     *
     * @param string $table
     * @param string $column
     * @return string|null
     */
    private function fkName(string $table, string $column): ?string
    {
        $sql = "SELECT CONSTRAINT_NAME 
              FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = :tbl
               AND COLUMN_NAME  = :col
               AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['tbl' => $table, 'col' => $column]);
        return $stmt->fetchColumn() ?: null;
    }

}
