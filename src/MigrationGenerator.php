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
    FOREIGN KEY (`{$jt->inverseColumn}`) REFERENCES `{$this->snake($meta->targetEntity ??$prop->getType()?->getName() ?? '')}`(`id`) ON DELETE CASCADE
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

                    $field        = $fa->newInstance();
                    $wantNullable = (bool) ($field->nullable ?? false);
                    $enumValues   = $field->enumValues ?? null;
                    $wantBase     = $this->mapToSqlBase($field->type ?? 'string', $field->length ?? null, $enumValues);
                    $wantDefault  = $field->default ?? null;
                    $wantSql      = "{$wantBase} " . ($wantNullable ? 'NULL' : 'NOT NULL')
                        . $this->renderDefault($wantDefault, $field->type ?? 'string');

                    if (!in_array($propName, $existingCols, true)) {
                        /* ① brand-new column */
                        $alterStmts[] = "ALTER TABLE `{$table}` ADD COLUMN `{$propName}` {$wantSql}";
                    } else {
                        /* ② column already present → compare & modify */
                        $colMeta      = $schema[$table][$propName] ?? null;
                        $haveNullable = (bool) ($colMeta['nullable'] ?? false);
                        $haveBase     = $this->mapToSqlBase($colMeta['type'] ?? '', $colMeta['length'] ?? null);
                        $haveDefault  = $colMeta['default'] ?? null;

                        if (
                            $wantNullable !== $haveNullable
                            || $wantBase     !== $haveBase
                            || $wantDefault  !== $haveDefault
                        ) {
                            $alterStmts[] = "ALTER TABLE `{$table}` MODIFY COLUMN `{$propName}` {$wantSql}";
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
        if ($sql !== '') {
            if (!str_ends_with($sql, ';')) {
                $sql .= ';';
            }
            $sql = "SET FOREIGN_KEY_CHECKS=0;\n{$sql}\nSET FOREIGN_KEY_CHECKS=1;";
        }
        if ($joinTableStmts) {
            $sql .= ($sql ? "\n\n" : '') . implode("\n", $joinTableStmts);
        }
        if ($dropStmts) {
            $drops = implode(";\n", $dropStmts);
            if (!str_ends_with($drops, ';')) {
                $drops .= ';';
            }

            $guard = "SET FOREIGN_KEY_CHECKS=0;\n{$drops}\nSET FOREIGN_KEY_CHECKS=1;";
            $sql  .= ($sql ? "\n\n" : '') . $guard;
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
    private function mapToSql(string $dbType, ?int $length = null, bool $nullable = false, ?array $enumValues = null): string
    {
        $null = $nullable ? ' NULL' : ' NOT NULL';

        return match (strtolower($dbType)) {
            // Strings
            'string'       => 'VARCHAR(' . ($length ?? 255) . ')' . $null,
            'char'         => 'CHAR('    . ($length ?? 1)   . ')' . $null,
            'text'         => 'TEXT'         . $null,
            'mediumtext'   => 'MEDIUMTEXT'   . $null,
            'longtext'     => 'LONGTEXT'     . $null,

            // Integers
            'int', 'integer'  => 'INT'     . $null,
            'tinyint'         => 'TINYINT' . ($length ? "($length)" : '(1)') . $null,
            'smallint'        => 'SMALLINT' . $null,
            'bigint'          => 'BIGINT'   . $null,
            'unsignedbigint'  => 'BIGINT UNSIGNED' . $null,

            // Decimal & float
            'decimal'         => 'DECIMAL(' . ($length ?? '10,2') . ')' . $null,
            'float'           => 'FLOAT' . $null,

            // Boolean
            'boolean'         => 'TINYINT(1)' . $null,

            // Date / time
            'date'            => 'DATE'      . $null,
            'time'            => 'TIME'      . $null,
            'datetime'        => 'DATETIME'  . $null,
            'datetimetz'      => 'DATETIME'  . $null,      // MySQL stores no TZ
            'timestamp'       => 'TIMESTAMP' . $null,
            'timestamptz'     => 'TIMESTAMP' . $null,      // idem
            'year'            => 'YEAR'      . $null,

            // UUID & binary/blob
            'uuid'            => 'CHAR(36)'  . $null,
            'binary'          => 'BLOB'      . $null,

            // JSON / arrays
            'json'            => 'JSON'      . $null,
            'simple_json',
            'array',
            'simple_array'    => 'TEXT'      . $null,     // serialize manually

            // Enum / Set   → use enumValues if provided, otherwise fall back to length
            'enum'            => 'ENUM(' . ($enumValues ? $this->formatEnumValues($enumValues) : ($length ?? "'value1','value2'")) . ')' . $null,
            'set'             => 'SET('  . ($enumValues ? $this->formatEnumValues($enumValues) : ($length ?? "'value1','value2'")) . ')' . $null,

            // Spatial
            'geometry'        => 'GEOMETRY'   . $null,
            'point'           => 'POINT'      . $null,
            'linestring'      => 'LINESTRING' . $null,
            'polygon'         => 'POLYGON'    . $null,

            // Network
            'ipaddress'       => 'VARCHAR(45)' . $null,   // IPv6-safe
            'macaddress'      => 'VARCHAR(17)' . $null,

            // Fallback
            default           => 'VARCHAR(' . ($length ?? 255) . ')' . $null,
        };
    }

    /**
     * Create a SQL CREATE TABLE statement for the given entity class.
     * Determines primary key from Field attributes with primaryKey=true.
     */
    private function createTableSql(ReflectionClass $ref, string $table): string
    {
        $cols = $this->getColumnDefinitions($ref);
        $defs = implode(",\n  ", $cols);

        // Find primary key from Field attributes
        $primaryKey = 'id'; // default fallback
        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $attrs = $prop->getAttributes(FieldAttr::class);
            if ($attrs) {
                $attr = $attrs[0]->newInstance();
                if ($attr->primaryKey) {
                    $primaryKey = $prop->getName();
                    break;
                }
            }
        }

        return <<<SQL
CREATE TABLE `{$table}` (
  {$defs},
  PRIMARY KEY (`{$primaryKey}`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    }

    /**
     * Extract scalar column definitions based on Field attributes.
     * Skips relations (ManyToMany, OneToMany, inverse OneToOne).
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

            /* Many-to-One owning side → FK column */
            if ($prop->getAttributes(ManyToOne::class)) {
                $m2o      = $prop->getAttributes(ManyToOne::class)[0]->newInstance();
                $colName  = $name . '_id';
                $nullable = $m2o->nullable ? ' NULL' : ' NOT NULL';
                $defs[$colName] = "`{$colName}` INT{$nullable}";
                continue;
            }

            /* One-to-One owning side → FK column */
            if ($prop->getAttributes(OneToOne::class)) {
                $o2o = $prop->getAttributes(OneToOne::class)[0]->newInstance();
                if (!$o2o->mappedBy) {
                    $colName  = $name . '_id';
                    $nullable = $o2o->nullable ? ' NULL' : ' NOT NULL';
                    $defs[$colName] = "`{$colName}` INT{$nullable}";
                    continue;
                }
            }

            /* Scalar field with #[Field] attribute */
            $attrs = $prop->getAttributes(FieldAttr::class);
            if ($attrs) {
                $fa         = $attrs[0]->newInstance();
                $type       = $fa->type ?? 'string';
                $length     = $fa->length ?? null;
                $nullable   = (bool)($fa->nullable ?? false);
                $enumValues = $fa->enumValues ?? null;
                $autoInc    = $fa->autoIncrement;

                // Build SQL type
                $sqlBase = $this->mapToSqlBase($type, $length, $enumValues);
                $sqlType = $this->mapToSql($type, $length, $nullable, $enumValues);
                $default = $fa->default ?? null;
                $phpType = $fa->type ?? $type;

                // Handle auto-increment
                $autoIncSuffix = $autoInc ? ' AUTO_INCREMENT' : '';

                $defs[$name] = "`{$name}` {$sqlType}{$autoIncSuffix}"
                    . $this->renderDefault($default, $phpType);
                continue;
            }

            /* #[Column] override */
            $colAttrs = $prop->getAttributes(ColumnAttr::class);
            if ($colAttrs) {
                /** @var ColumnAttr $attr */
                $attr     = $colAttrs[0]->newInstance();
                $sqlType  = strtoupper($attr->type ?? 'VARCHAR');
                $length   = $attr->length ? "({$attr->length})" : '';
                $nullable = $attr->nullable ? ' NULL' : ' NOT NULL';
                $defs[$name] = "`{$name}` {$sqlType}{$length}{$nullable}"
                    . $this->renderDefault($attr->default ?? null, $attr->type ?? 'string');
                continue;
            }

            /* Fallback for properties without attributes */
            $type = $prop->getType()?->getName() ?? 'string';
            $sqlType = $this->mapToSql($type, null, false);
            $defs[$name] = "`{$name}` {$sqlType}";
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
     * Map a PHP/DSL type to the raw MySQL column definition
     * *without* any NULL / NOT NULL suffix.
     *
     * @param string      $dbType  logical type name (case-insensitive)
     * @param int|string|null $length optional length / precision
     * @param array|null $enumValues optional enum values
     */
    private function mapToSqlBase(string $dbType, $length = null, ?array $enumValues = null): string
    {
        return match (strtolower($dbType)) {
            'string'             => 'VARCHAR(' . ($length ?? 255) . ')',
            'char'               => 'CHAR(' . ($length ?? 1) . ')',
            'text'               => 'TEXT',
            'mediumtext'         => 'MEDIUMTEXT',
            'longtext'           => 'LONGTEXT',
            'int', 'integer'     => 'INT',
            'tinyint'            => 'TINYINT' . ($length ? "($length)" : '(1)'),
            'smallint'           => 'SMALLINT',
            'bigint'             => 'BIGINT',
            'unsignedbigint'     => 'BIGINT UNSIGNED',
            'decimal'            => 'DECIMAL(' . ($length ?? '10,2') . ')',
            'float', 'double'    => 'FLOAT',
            'boolean', 'bool'    => 'TINYINT(1)',
            'date'               => 'DATE',
            'time'               => 'TIME',
            'datetime'           => 'DATETIME',
            'datetimetz'         => 'DATETIME',
            'timestamp'          => 'TIMESTAMP',
            'timestamptz'        => 'TIMESTAMP',
            'year'               => 'YEAR',
            'uuid'               => 'CHAR(36)',
            'binary'             => 'BLOB',
            'json'               => 'JSON',
            'simple_json', 'array', 'simple_array' => 'TEXT',
            'enum'               => 'ENUM(' . ($enumValues ? $this->formatEnumValues($enumValues) : ($length ?? "'value1','value2'")) . ')',
            'set'                => 'SET(' . ($enumValues ? $this->formatEnumValues($enumValues) : ($length ?? "'value1','value2'")) . ')',
            'geometry'           => 'GEOMETRY',
            'point'              => 'POINT',
            'linestring'         => 'LINESTRING',
            'polygon'            => 'POLYGON',
            'ipaddress'          => 'VARCHAR(45)',
            'macaddress'         => 'VARCHAR(17)',
            default              => 'VARCHAR(' . ($length ?? 255) . ')',
        };
    }

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

    private function formatEnumValues(array $values): string
    {
        return implode(',', array_map(fn($v) => "'" . addslashes($v) . "'", $values));
    }

    private function renderDefault(mixed $value, string $phpType): string
    {
        if ($value === null) {
            return '';
        }

        $phpTypeLower = \strtolower($phpType);

        // For ENUM and SET, the value should be a simple quoted string
        if ($phpTypeLower === 'enum' || $phpTypeLower === 'set') {
            return " DEFAULT '" . (string) $value . "'";
        }

        // quote strings / JSON – leave numbers & booleans alone
        $needsQuotes = match ($phpTypeLower) {
            'string', 'text', 'char', 'uuid', 'json', 'simple_json',
            'array', 'simple_array' => true,
            default => false,
        };

        $literal = $needsQuotes ? $this->db->pdo()->quote((string) $value)
            : (string) $value;

        return " DEFAULT {$literal}";
    }
}
