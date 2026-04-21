<?php

declare(strict_types=1);

namespace MonkeysLegion\Migration;

use DateTimeImmutable;
use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Database\Types\DatabaseDriver;
use MonkeysLegion\Entity\Attributes\Column;
use MonkeysLegion\Entity\Attributes\Field;
use MonkeysLegion\Entity\Attributes\ManyToMany;
use MonkeysLegion\Entity\Attributes\JoinTable;
use MonkeysLegion\Entity\Attributes\ManyToOne;
use MonkeysLegion\Entity\Attributes\OneToMany;
use MonkeysLegion\Entity\Attributes\OneToOne;
use MonkeysLegion\Entity\Metadata\MetadataRegistry;
use MonkeysLegion\Migration\Dialect\MySqlDialect;
use MonkeysLegion\Migration\Dialect\PostgreSqlDialect;
use MonkeysLegion\Migration\Dialect\SqlDialect;
use PDO;

final class MigrationGenerator
{
    /** Tables that must never be dropped automatically. */
    private array $protectedTables = ['migrations', 'ml_migrations'];

    /** Detected database driver. */
    private DatabaseDriver $driver;

    /** Dialect strategy resolved from the driver. */
    private SqlDialect $dialect;

    public function __construct(private readonly ConnectionInterface $db)
    {
        $this->driver = $this->db->getDriver();

        $this->dialect = match ($this->driver) {
            DatabaseDriver::PostgreSQL => new PostgreSqlDialect(),
            DatabaseDriver::MySQL      => new MySqlDialect(),
            default => throw new \RuntimeException(
                sprintf(
                    "Unsupported driver '%s'. Supported drivers: 'mysql', 'pgsql'.",
                    $this->driver->label(),
                ),
            ),
        };
    }

    // ─── public API ─────────────────────────────────────────────────

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

        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, $template);

        return $file;
    }

    /**
     * Compute SQL to migrate current DB schema to match entity metadata.
     * Produces dialect-appropriate DDL for both MySQL and PostgreSQL.
     */
    public function diff(array $entities, array $schema): string
    {
        $q = fn(string $id): string => $this->dialect->quoteIdentifier($id);

        $alterStmts     = [];
        $joinTableStmts = [];

        $seenEntityTables = [];
        $seenJoinTables   = [];

        // First pass: collect primary key types and names for each entity
        $primaryKeyTypes = [];
        $primaryKeyNames = [];
        $entityTableMap  = [];
        foreach ($entities as $entityFqcn) {
            $entityMeta = MetadataRegistry::for(is_string($entityFqcn) ? $entityFqcn : $entityFqcn->getName());
            $table = $entityMeta->table;
            
            $entityTableMap[$entityMeta->className] = $table;
            $primaryKeyNames[$table]          = $entityMeta->primaryKey ?? 'id';
            
            if ($entityMeta->primaryKey && isset($entityMeta->fields[$entityMeta->primaryKey])) {
                $primaryKeyTypes[$table] = $entityMeta->fields[$entityMeta->primaryKey]->type;
            } else {
                $primaryKeyTypes[$table] = 'int';
            }
        }

        foreach ($entities as $entityFqcn) {
            $entityMeta = MetadataRegistry::for(is_string($entityFqcn) ? $entityFqcn : $entityFqcn->getName());
            $table = $entityMeta->table;
            $ref   = new \ReflectionClass($entityMeta->className);

            $seenEntityTables[$table] = true;

            if (!isset($schema[$table])) {
                $alterStmts[] = $this->createTableSql($entityMeta);
                // Mark table as seen; skip per-column diff — CREATE TABLE
                // already includes every column.
                $schema[$table] = ['__new__' => true];
                $seenEntityTables[$table] = true;

                // Still need to process ManyToMany join tables for this entity
                foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
                    foreach ($prop->getAttributes(ManyToMany::class) as $attr) {
                        $m2m = $attr->newInstance();
                        if ($m2m->joinTable instanceof JoinTable) {
                            $jt = $m2m->joinTable;
                            $seenJoinTables[$jt->name] = true;
                            
                            $targetEntityClass = $m2m->targetEntity ?? $prop->getType()?->getName() ?? '';
                            $targetMeta  = is_string($targetEntityClass) && class_exists($targetEntityClass)
                                ? MetadataRegistry::for($targetEntityClass)
                                : null;
                            
                            $targetTable = $targetMeta ? $targetMeta->table : $this->snake($targetEntityClass);
                            $ownerPk     = $primaryKeyNames[$table] ?? 'id';
                            $targetPk    = $primaryKeyNames[$targetTable] ?? 'id';

                            $joinTableStmts[$jt->name] =
                                "CREATE TABLE IF NOT EXISTS {$q($jt->name)} (\n"
                                . "    {$q($jt->joinColumn)} INT NOT NULL,\n"
                                . "    {$q($jt->inverseColumn)} INT NOT NULL,\n"
                                . "    PRIMARY KEY ({$q($jt->joinColumn)}, {$q($jt->inverseColumn)}),\n"
                                . "    FOREIGN KEY ({$q($jt->joinColumn)})   REFERENCES {$q($table)}({$q($ownerPk)})   ON DELETE CASCADE,\n"
                                . "    FOREIGN KEY ({$q($jt->inverseColumn)}) REFERENCES {$q($targetTable)}({$q($targetPk)}) ON DELETE CASCADE\n"
                                . ")" . $this->dialect->engineSuffix() . ";";
                        }
                    }
                }
                continue; // skip per-column diff for this newly created table
            }

            $existingCols = array_keys($schema[$table] ?? []);
            $skipCols     = [];
            $pkName       = $primaryKeyNames[$table] ?? 'id';
            $expectedCols = [$pkName => true];

            foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
                $propName = $prop->getName();

                // ManyToMany → remember expected join table
                foreach ($prop->getAttributes(ManyToMany::class) as $attr) {
                    $m2m = $attr->newInstance();
                    if ($m2m->joinTable instanceof JoinTable) {
                        $jt = $m2m->joinTable;
                        $seenJoinTables[$jt->name] = true;

                        $targetEntityClass = $m2m->targetEntity ?? $prop->getType()?->getName() ?? '';
                        $targetMeta  = is_string($targetEntityClass) && class_exists($targetEntityClass)
                            ? MetadataRegistry::for($targetEntityClass)
                            : null;
                        
                        $targetTable = $targetMeta ? $targetMeta->table : $this->snake($targetEntityClass);
                        $ownerPk     = $primaryKeyNames[$table] ?? 'id';
                        $targetPk    = $primaryKeyNames[$targetTable] ?? 'id';

                        $joinTableStmts[$jt->name] =
                            "CREATE TABLE IF NOT EXISTS {$q($jt->name)} (\n"
                            . "    {$q($jt->joinColumn)} INT NOT NULL,\n"
                            . "    {$q($jt->inverseColumn)} INT NOT NULL,\n"
                            . "    PRIMARY KEY ({$q($jt->joinColumn)}, {$q($jt->inverseColumn)}),\n"
                            . "    FOREIGN KEY ({$q($jt->joinColumn)})   REFERENCES {$q($table)}({$q($ownerPk)})   ON DELETE CASCADE,\n"
                            . "    FOREIGN KEY ({$q($jt->inverseColumn)}) REFERENCES {$q($targetTable)}({$q($targetPk)}) ON DELETE CASCADE\n"
                            . ")" . $this->dialect->engineSuffix() . ";";
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

                // Many-to-One owning side: FK col honours nullable AND type
                if ($prop->getAttributes(ManyToOne::class)) {
                    $m2o   = $prop->getAttributes(ManyToOne::class)[0]->newInstance();
                    $fkCol = str_ends_with($propName, '_id') ? $propName : $propName . '_id';

                    // If this FK column is also the PK, don't re-add it (shared PK/FK pattern)
                    if ($fkCol === $pkName) {
                        $skipCols[] = $propName;
                        continue;
                    }

                    $expectedCols[$fkCol] = true;

                    if (!in_array($fkCol, $existingCols, true)) {
                        $null        = $m2o->nullable ? ' NULL' : ' NOT NULL';
                        $targetTable = $entityTableMap[$m2o->targetEntity] ?? $this->snake($m2o->targetEntity);
                        $targetPk    = $primaryKeyNames[$targetTable] ?? 'id';
                        $fkType      = isset($primaryKeyTypes[$targetTable]) && $primaryKeyTypes[$targetTable] === 'uuid'
                            ? $this->dialect->uuidFkType()
                            : $this->dialect->intFkType();

                        $alterStmts[] = "ALTER TABLE {$q($table)} ADD COLUMN {$q($fkCol)} {$fkType}{$null}";
                        $alterStmts[] = "ALTER TABLE {$q($table)} ADD CONSTRAINT {$q("fk_{$table}_{$fkCol}")} FOREIGN KEY ({$q($fkCol)}) REFERENCES {$q($targetTable)}({$q($targetPk)})" . ($m2o->nullable ? ' ON DELETE SET NULL' : '');
                    } else {
                        /* FK column already exists → compare type & nullability */
                        $colMeta      = $schema[$table][$fkCol] ?? null;
                        $haveNullable = (bool) ($colMeta['nullable'] ?? false);
                        $targetTable  = $entityTableMap[$m2o->targetEntity] ?? $this->snake($m2o->targetEntity);
                        $fkType       = isset($primaryKeyTypes[$targetTable]) && $primaryKeyTypes[$targetTable] === 'uuid'
                            ? $this->dialect->uuidFkType()
                            : $this->dialect->intFkType();

                        // mapType handles standardizing e.g. 'int' to 'INT' or 'string' to 'VARCHAR(255)'
                        $haveBase = $this->dialect->mapType($colMeta['type'] ?? '', $colMeta['length'] ?? null);
                        $wantBase = $fkType;

                        if ($m2o->nullable !== $haveNullable || $wantBase !== $haveBase) {
                            $alterStmts[] = $this->dialect->alterColumnSql(
                                $table,
                                $fkCol,
                                $wantBase,
                                $m2o->nullable,
                                '', // no default for FK
                            );
                        }
                    }
                    $skipCols[] = $propName;
                    continue;
                }

                // One-to-One owning side (no mappedBy): FK col honours nullable AND type
                if ($prop->getAttributes(OneToOne::class)) {
                    $o2o = $prop->getAttributes(OneToOne::class)[0]->newInstance();
                    if (!$o2o->mappedBy) {
                        $fkCol = str_ends_with($propName, '_id') ? $propName : $propName . '_id';

                        // If this FK column is also the PK, don't re-add it (shared PK/FK pattern)
                        if ($fkCol === $pkName) {
                            $skipCols[] = $propName;
                            continue;
                        }

                        $expectedCols[$fkCol] = true;

                        if (!in_array($fkCol, $existingCols, true)) {
                            $null        = $o2o->nullable ? ' NULL' : ' NOT NULL';
                            $targetTable = $entityTableMap[$o2o->targetEntity] ?? $this->snake($o2o->targetEntity);
                            $targetPk    = $primaryKeyNames[$targetTable] ?? 'id';
                            $fkType      = isset($primaryKeyTypes[$targetTable]) && $primaryKeyTypes[$targetTable] === 'uuid'
                                ? $this->dialect->uuidFkType()
                                : $this->dialect->intFkType();

                            $alterStmts[] = "ALTER TABLE {$q($table)} ADD COLUMN {$q($fkCol)} {$fkType}{$null}";
                            $alterStmts[] = "ALTER TABLE {$q($table)} ADD CONSTRAINT {$q("fk_{$table}_{$fkCol}")} FOREIGN KEY ({$q($fkCol)}) REFERENCES {$q($targetTable)}({$q($targetPk)})" . ($o2o->nullable ? ' ON DELETE SET NULL' : '');
                        } else {
                            /* FK column already exists → compare type & nullability */
                            $colMeta      = $schema[$table][$fkCol] ?? null;
                            $haveNullable = (bool) ($colMeta['nullable'] ?? false);
                            $targetTable  = $entityTableMap[$o2o->targetEntity] ?? $this->snake($o2o->targetEntity);
                            $fkType       = isset($primaryKeyTypes[$targetTable]) && $primaryKeyTypes[$targetTable] === 'uuid'
                                ? $this->dialect->uuidFkType()
                                : $this->dialect->intFkType();

                            $haveBase = $this->dialect->mapType($colMeta['type'] ?? '', $colMeta['length'] ?? null);
                            $wantBase = $fkType;

                            if ($o2o->nullable !== $haveNullable || $wantBase !== $haveBase) {
                                $alterStmts[] = $this->dialect->alterColumnSql(
                                    $table,
                                    $fkCol,
                                    $wantBase,
                                    $o2o->nullable,
                                    '', // no default for FK
                                );
                            }
                        }
                        $skipCols[] = $propName;
                        continue;
                    }
                }

                // Primary key — skip (already in $expectedCols)
                if ($propName === $pkName) {
                    $expectedCols[$pkName] = true;
                    continue;
                }

                // Scalar fields from Metadata
                foreach ($entityMeta->fields as $propName => $field) {
                    if ($propName === $pkName || in_array($propName, $skipCols, true)) {
                        continue;
                    }
                    $expectedCols[$propName] = true;

                    $wantNullable = $field->nullable;
                    $enumValues   = $field->enumValues;
                    $wantBase     = $this->dialect->mapType($field->type, $field->length, $enumValues);
                    $wantDefault  = $field->default;
                    $wantSql      = "{$wantBase} " . ($wantNullable ? 'NULL' : 'NOT NULL')
                        . $this->renderDefault($wantDefault, $field->type);

                    if (!in_array($propName, $existingCols, true)) {
                        /* ① brand-new column */
                        $alterStmts[] = "ALTER TABLE {$q($table)} ADD COLUMN {$q($propName)} {$wantSql}";
                    } else {
                        /* ② column already present → compare & modify */
                        $colMeta      = $schema[$table][$propName] ?? null;
                        $haveNullable = (bool) ($colMeta['nullable'] ?? false);
                        $haveBase     = $this->dialect->mapType($colMeta['type'] ?? '', $colMeta['length'] ?? null);
                        $haveDefault  = $colMeta['default'] ?? null;

                        // Normalize booleans for comparison
                        $wantNorm = $wantDefault;
                        $haveNorm = $haveDefault;

                        if (strtolower($field->type) === 'boolean' || strtolower($field->type) === 'bool') {
                            if ($wantDefault !== null) {
                                $wantNorm = $wantDefault ? 'TRUE' : 'FALSE';
                            }
                            if ($haveDefault !== null) {
                                if ($haveDefault === '0' || $haveDefault === 0 || strcasecmp((string)$haveDefault, 'false') === 0) {
                                    $haveNorm = 'FALSE';
                                } elseif ($haveDefault === '1' || $haveDefault === 1 || strcasecmp((string)$haveDefault, 'true') === 0) {
                                    $haveNorm = 'TRUE';
                                }
                            }
                        }

                        if (
                            $wantNullable !== $haveNullable
                            || $wantBase     !== $haveBase
                            || $wantNorm     !== $haveNorm
                        ) {
                            $defaultClause = $this->renderDefault($wantDefault, $field->type);
                            $alterStmts[] = $this->dialect->alterColumnSql(
                                $table,
                                $propName,
                                $wantBase,
                                $wantNullable,
                                $defaultClause,
                            );
                        }
                    }
                }

                // #[Column] override
                if ($prop->getAttributes(Field::class)) {
                    if (in_array($propName, $skipCols, true)) {
                        continue;
                    }
                    $expectedCols[$propName] = true;

                    if (!in_array($propName, $existingCols, true)) {
                        $attr    = $prop->getAttributes(Field::class)[0]->newInstance();
                        $sqlType = strtoupper($attr->type ?? 'VARCHAR');
                        $length  = $attr->length ? "({$attr->length})" : '';
                        $null    = $attr->nullable ? ' NULL' : ' NOT NULL';
                        $alterStmts[] = "ALTER TABLE {$q($table)} ADD COLUMN {$q($propName)} {$sqlType}{$length}{$null}";
                    }
                }
            }

            // ➋ DROP logic: anything in $existingCols not in $expectedCols should be dropped.
            $metadataCols = ['COLUMN_NAME', 'COLUMN_TYPE', 'IS_NULLABLE'];
            foreach ($existingCols as $col) {
                if ($col === $pkName) continue;
                if (in_array(strtoupper($col), $metadataCols, true)) continue;
                if (!isset($expectedCols[$col])) {
                    if (str_ends_with($col, '_id')) {
                        $fkName = $this->fkName($table, $col);
                        if ($fkName) {
                            $alterStmts[] = $this->dialect->dropForeignKeySql($table, $fkName);
                        }
                    }
                    $cascade = $this->driver === DatabaseDriver::PostgreSQL ? ' CASCADE' : '';
                    $alterStmts[] = "ALTER TABLE {$q($table)} DROP COLUMN {$q($col)}{$cascade}";
                }
            }
        }

        // ➊ DROP tables that are not expected
        $dropStmts = [];

        foreach (array_keys($schema) as $tbl) {
            if (in_array($tbl, $this->protectedTables, true)) {
                continue;
            }

            $isEntityExpected = isset($seenEntityTables[$tbl]);
            $isJoinExpected   = isset($seenJoinTables[$tbl]);

            if (! $isEntityExpected && ! $isJoinExpected) {
                $cascade = $this->driver === DatabaseDriver::PostgreSQL ? ' CASCADE' : '';
                $dropStmts[] = "DROP TABLE IF EXISTS {$q($tbl)}{$cascade}";
            }
        }

        // Compose final SQL — order: CREATE/ALTER first, JOIN tables, then DROPs last.
        // This ensures new tables and FK constraints are created before
        // orphaned tables are dropped (important with CASCADE on PG).
        $disableFk = $this->dialect->disableFkChecks();
        $enableFk  = $this->dialect->enableFkChecks();

        $parts = [];

        // ① CREATE + ALTER statements
        if ($alterStmts) {
            $alterSql = implode(";\n", $alterStmts);
            if (!str_ends_with($alterSql, ';')) {
                $alterSql .= ';';
            }
            if ($disableFk !== '' && $enableFk !== '') {
                $alterSql = "{$disableFk}\n{$alterSql}\n{$enableFk}";
            }
            $parts[] = $alterSql;
        }

        // ② JOIN table creates
        if ($joinTableStmts) {
            $parts[] = implode("\n", $joinTableStmts);
        }

        // ③ DROP statements (always last)
        if ($dropStmts) {
            $dropSql = implode(";\n", $dropStmts);
            if (!str_ends_with($dropSql, ';')) {
                $dropSql .= ';';
            }
            if ($disableFk !== '' && $enableFk !== '') {
                $dropSql = "{$disableFk}\n{$dropSql}\n{$enableFk}";
            }
            $parts[] = $dropSql;
        }

        $sql = implode("\n\n", $parts);

        return $sql === '' ? '' : rtrim($sql, ";\n") . ';';
    }

    // ─── private helpers ────────────────────────────────────────────

    /**
     * Create a SQL CREATE TABLE statement for the given entity class.
     */
    private function createTableSql(\MonkeysLegion\Entity\Metadata\EntityMetadata $entityMeta): string
    {
        $q    = fn(string $id): string => $this->dialect->quoteIdentifier($id);
        $cols = $this->getColumnDefinitions($entityMeta);
        $defs = implode(",\n  ", $cols);

        $primaryKey = $entityMeta->primaryKey ?? 'id';
        $table      = $entityMeta->table;

        $suffix = $this->dialect->engineSuffix();
        $suffix = $suffix ? "\n{$suffix}" : '';

        return <<<SQL
CREATE TABLE {$q($table)} (
  {$defs},
  PRIMARY KEY ({$q($primaryKey)})
){$suffix}
SQL;
    }

    /**
     * Extract scalar column definitions based on Field attributes.
     */
    private function getColumnDefinitions(\MonkeysLegion\Entity\Metadata\EntityMetadata $entityMeta): array
    {
        $q    = fn(string $id): string => $this->dialect->quoteIdentifier($id);
        $defs = [];
        $ref  = new \ReflectionClass($entityMeta->className);

        $primaryKeyName = $entityMeta->primaryKey ?? 'id';

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();

            // 1. Relationships (Owning side)
            if ($prop->getAttributes(ManyToOne::class)) {
                $m2o      = $prop->getAttributes(ManyToOne::class)[0]->newInstance();
                $colName  = str_ends_with($name, '_id') ? $name : $name . '_id';

                if ($colName === $primaryKeyName) {
                    continue;
                }

                $targetMeta   = MetadataRegistry::for($m2o->targetEntity);
                $targetPkType = 'int';
                if ($targetMeta->primaryKey && isset($targetMeta->fields[$targetMeta->primaryKey])) {
                    $targetPkType = $targetMeta->fields[$targetMeta->primaryKey]->type;
                }

                $nullable = $m2o->nullable ? ' NULL' : ' NOT NULL';
                $fkType   = $targetPkType === 'uuid'
                    ? $this->dialect->uuidFkType()
                    : $this->dialect->intFkType();
                
                $defs[$colName] = "{$q($colName)} {$fkType}{$nullable}";
                continue;
            }

            if ($prop->getAttributes(OneToOne::class)) {
                $o2o = $prop->getAttributes(OneToOne::class)[0]->newInstance();
                if (!$o2o->mappedBy) {
                    $colName  = str_ends_with($name, '_id') ? $name : $name . '_id';

                    if ($colName === $primaryKeyName) {
                        continue;
                    }

                    $targetMeta   = MetadataRegistry::for($o2o->targetEntity);
                    $targetPkType = 'int';
                    if ($targetMeta->primaryKey && isset($targetMeta->fields[$targetMeta->primaryKey])) {
                        $targetPkType = $targetMeta->fields[$targetMeta->primaryKey]->type;
                    }

                    $nullable = $o2o->nullable ? ' NULL' : ' NOT NULL';
                    $fkType   = $targetPkType === 'uuid'
                        ? $this->dialect->uuidFkType()
                        : $this->dialect->intFkType();
                    
                    $defs[$colName] = "{$q($colName)} {$fkType}{$nullable}";
                    continue;
                }
            }
        }

        // 2. Scalar fields from Metadata
        foreach ($entityMeta->fields as $name => $field) {
            $type       = $field->type;
            $length     = $field->length;
            $nullable   = $field->nullable;
            $enumValues = $field->enumValues;
            $autoInc    = $field->autoIncrement;

            if ($autoInc) {
                $sqlBase       = $this->dialect->autoIncrementType($type);
                $nullSuffix    = $nullable ? ' NULL' : ' NOT NULL';
                $autoIncSuffix = $this->dialect->autoIncrementKeyword();
                $defs[$name]   = "{$q($name)} {$sqlBase}{$nullSuffix}{$autoIncSuffix}"
                    . $this->renderDefault($field->default, $type);
            } else {
                $sqlType     = $this->dialect->mapTypeWithNullability($type, $length, $nullable, $enumValues);
                $defs[$name] = "{$q($name)} {$sqlType}"
                    . $this->renderDefault($field->default, $type);
            }
        }

        return $defs;
    }

    /**
     * Generate a reverse migration SQL from the given SQL.
     * Handles both backtick (MySQL) and double-quote (PG) identifier quoting.
     */
    private function autoReverse(string $sql): string
    {
        $q     = fn(string $id): string => $this->dialect->quoteIdentifier($id);
        $lines = array_filter(array_map('trim', explode("\n", $sql)));
        $out   = [];

        // Pattern matches both `name` and "name"
        $idPat = '[`"](\w+)[`"]';

        foreach ($lines as $line) {
            $cascade = $this->driver === DatabaseDriver::PostgreSQL ? ' CASCADE' : '';
            if (preg_match('/^CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+' . $idPat . '/i', $line, $m)) {
                $out[] = "DROP TABLE IF EXISTS {$q($m[1])}{$cascade};";
            } elseif (preg_match('/^ALTER\s+TABLE\s+' . $idPat . '\s+ADD\s+COLUMN\s+' . $idPat . '/i', $line, $m)) {
                $out[] = "ALTER TABLE {$q($m[1])} DROP COLUMN {$q($m[2])}{$cascade};";
            } else {
                $out[] = "-- TODO reverse: {$line}";
            }
        }

        return implode("\n", $out);
    }

    /**
     * Look up the FK constraint name for a given table + column.
     */
    private function fkName(string $table, string $column): ?string
    {
        $sql    = $this->dialect->foreignKeyLookupSql();
        $params = $this->dialect->foreignKeyLookupParams($table, $column);

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Convert a fully qualified class name to a snake_case table name.
     */
    private function snake(string $class): string
    {
        $base = str_contains($class, '\\') ? substr($class, strrpos($class, '\\') + 1) : $class;
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $base));
    }

    /** Is this property a relation attribute? */
    private function isRelation(\ReflectionProperty $p): bool
    {
        return $p->getAttributes(OneToOne::class)
            || $p->getAttributes(OneToMany::class)
            || $p->getAttributes(ManyToOne::class)
            || $p->getAttributes(ManyToMany::class);
    }

    private function formatEnumValues(array $values): string
    {
        return implode(',', array_map(fn($v) => "'" . addslashes((string) $v) . "'", $values));
    }

    private function renderDefault(mixed $value, string $phpType): string
    {
        if ($value === null) {
            return '';
        }

        $phpTypeLower = strtolower($phpType);

        // Boolean: render TRUE / FALSE explicitly (handles PHP false → '' cast)
        if ($phpTypeLower === 'boolean' || $phpTypeLower === 'bool') {
            return ' DEFAULT ' . ($value ? 'TRUE' : 'FALSE');
        }

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
