<?php

declare(strict_types=1);

namespace MonkeysLegion\Migration;

use DateTimeImmutable;
use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Entity\Attributes\Column as ColumnAttr;
use MonkeysLegion\Entity\Attributes\Field as FieldAttr;
use MonkeysLegion\Entity\Attributes\Id as IdAttr;
use MonkeysLegion\Entity\Attributes\JoinTable;
use MonkeysLegion\Entity\Attributes\ManyToMany;
use MonkeysLegion\Entity\Attributes\ManyToOne;
use MonkeysLegion\Entity\Attributes\OneToMany;
use MonkeysLegion\Entity\Attributes\OneToOne;
use MonkeysLegion\Migration\Dialect\MySqlDialect;
use MonkeysLegion\Migration\Dialect\PostgreSqlDialect;
use MonkeysLegion\Migration\Dialect\SqlDialect;
use PDO;
use ReflectionClass;
use ReflectionProperty;

final class MigrationGenerator
{
    /** Tables that must never be dropped automatically. */
    private array $protectedTables = ['migrations'];

    /** Detected PDO driver name ('mysql' | 'pgsql'). */
    private string $driver;

    /** Dialect strategy resolved from the driver. */
    private SqlDialect $dialect;

    public function __construct(private ConnectionInterface $db)
    {
        $this->driver = $this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

        $this->dialect = match ($this->driver) {
            'pgsql' => new PostgreSqlDialect(),
            'mysql' => new MySqlDialect(),
            default => throw new \RuntimeException(
                sprintf(
                    "Unsupported PDO driver '%s'. Supported drivers: 'mysql', 'pgsql'.",
                    $this->driver,
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
        foreach ($entities as $entityFqcn) {
            $ref   = $entityFqcn instanceof ReflectionClass ? $entityFqcn : new ReflectionClass($entityFqcn);
            $table = strtolower($ref->getShortName());

            foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                // Prefer #[Id] attribute for PK detection
                if ($prop->getAttributes(IdAttr::class)) {
                    $primaryKeyNames[$table] = $prop->getName();
                    $fieldAttrs = $prop->getAttributes(FieldAttr::class);
                    if ($fieldAttrs) {
                        $fa = $fieldAttrs[0]->newInstance();
                        $primaryKeyTypes[$table] = $fa->type ?? 'int';
                    } else {
                        $primaryKeyTypes[$table] = 'int';
                    }
                    break;
                }
                // Fallback: #[Field(primaryKey: true)]
                $attrs = $prop->getAttributes(FieldAttr::class);
                if ($attrs) {
                    $attr = $attrs[0]->newInstance();
                    if ($attr->primaryKey) {
                        $primaryKeyNames[$table] = $prop->getName();
                        $primaryKeyTypes[$table] = $attr->type ?? 'int';
                        break;
                    }
                }
            }
            if (!isset($primaryKeyTypes[$table])) {
                $primaryKeyTypes[$table] = 'int';
            }
            if (!isset($primaryKeyNames[$table])) {
                $primaryKeyNames[$table] = 'id';
            }
        }

        foreach ($entities as $entityFqcn) {
            $ref   = $entityFqcn instanceof ReflectionClass ? $entityFqcn : new ReflectionClass($entityFqcn);
            $table = strtolower($ref->getShortName());

            $seenEntityTables[$table] = true;

            if (!isset($schema[$table])) {
                $alterStmts[] = $this->createTableSql($ref, $table);
                // Mark table as seen; skip per-column diff — CREATE TABLE
                // already includes every column.
                $schema[$table] = ['__new__' => true];
                $seenEntityTables[$table] = true;

                // Still need to process ManyToMany join tables for this entity
                foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                    foreach ($prop->getAttributes(ManyToMany::class) as $attr) {
                        $meta = $attr->newInstance();
                        if ($meta->joinTable instanceof JoinTable) {
                            $jt = $meta->joinTable;
                            $seenJoinTables[$jt->name] = true;
                            $targetTable = $this->snake($meta->targetEntity ?? $prop->getType()?->getName() ?? '');
                            $ownerPk  = $primaryKeyNames[$table] ?? 'id';
                            $targetPk = $primaryKeyNames[$targetTable] ?? 'id';

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

            foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                $propName = $prop->getName();

                // ManyToMany → remember expected join table
                foreach ($prop->getAttributes(ManyToMany::class) as $attr) {
                    /** @var ManyToMany $meta */
                    $meta = $attr->newInstance();
                    if ($meta->joinTable instanceof JoinTable) {
                        $jt = $meta->joinTable;
                        $seenJoinTables[$jt->name] = true;
                        $targetTable = $this->snake($meta->targetEntity ?? $prop->getType()?->getName() ?? '');
                        $ownerPk  = $primaryKeyNames[$table] ?? 'id';
                        $targetPk = $primaryKeyNames[$targetTable] ?? 'id';

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
                        $targetTable = $this->snake($m2o->targetEntity);
                        $targetPk    = $primaryKeyNames[$targetTable] ?? 'id';
                        $fkType      = isset($primaryKeyTypes[$targetTable]) && $primaryKeyTypes[$targetTable] === 'uuid'
                            ? $this->dialect->uuidFkType()
                            : $this->dialect->intFkType();

                        $alterStmts[] = "ALTER TABLE {$q($table)} ADD COLUMN {$q($fkCol)} {$fkType}{$null}";
                        $alterStmts[] = "ALTER TABLE {$q($table)} ADD CONSTRAINT {$q("fk_{$table}_{$fkCol}")} FOREIGN KEY ({$q($fkCol)}) REFERENCES {$q($targetTable)}({$q($targetPk)})" . ($m2o->nullable ? ' ON DELETE SET NULL' : '');
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
                            $targetTable = $this->snake($o2o->targetEntity);
                            $targetPk    = $primaryKeyNames[$targetTable] ?? 'id';
                            $fkType      = isset($primaryKeyTypes[$targetTable]) && $primaryKeyTypes[$targetTable] === 'uuid'
                                ? $this->dialect->uuidFkType()
                                : $this->dialect->intFkType();

                            $alterStmts[] = "ALTER TABLE {$q($table)} ADD COLUMN {$q($fkCol)} {$fkType}{$null}";
                            $alterStmts[] = "ALTER TABLE {$q($table)} ADD CONSTRAINT {$q("fk_{$table}_{$fkCol}")} FOREIGN KEY ({$q($fkCol)}) REFERENCES {$q($targetTable)}({$q($targetPk)})" . ($o2o->nullable ? ' ON DELETE SET NULL' : '');
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

                // Scalar #[Field]
                foreach ($prop->getAttributes(FieldAttr::class) as $fa) {
                    if (in_array($propName, $skipCols, true)) {
                        continue 2;
                    }
                    $expectedCols[$propName] = true;

                    $field        = $fa->newInstance();
                    $wantNullable = (bool) ($field->nullable ?? false);
                    $enumValues   = $field->enumValues ?? null;
                    $wantBase     = $this->dialect->mapType($field->type ?? 'string', $field->length ?? null, $enumValues);
                    $wantDefault  = $field->default ?? null;
                    $wantSql      = "{$wantBase} " . ($wantNullable ? 'NULL' : 'NOT NULL')
                        . $this->renderDefault($wantDefault, $field->type ?? 'string');

                    if (!in_array($propName, $existingCols, true)) {
                        /* ① brand-new column */
                        $alterStmts[] = "ALTER TABLE {$q($table)} ADD COLUMN {$q($propName)} {$wantSql}";
                    } else {
                        /* ② column already present → compare & modify */
                        $colMeta      = $schema[$table][$propName] ?? null;
                        $haveNullable = (bool) ($colMeta['nullable'] ?? false);
                        $haveBase     = $this->dialect->mapType($colMeta['type'] ?? '', $colMeta['length'] ?? null);
                        $haveDefault  = $colMeta['default'] ?? null;

                        if (
                            $wantNullable !== $haveNullable
                            || $wantBase     !== $haveBase
                            || $wantDefault  !== $haveDefault
                        ) {
                            $defaultClause = $this->renderDefault($wantDefault, $field->type ?? 'string');
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
                        $alterStmts[] = "ALTER TABLE {$q($table)} ADD COLUMN {$q($propName)} {$sqlType}{$length}{$null}";
                    }
                }
            }

            // ➋ DROP logic: anything in $existingCols not in $expectedCols should be dropped.
            foreach ($existingCols as $col) {
                if ($col === $pkName) continue;
                if (!isset($expectedCols[$col])) {
                    if (str_ends_with($col, '_id')) {
                        $fkName = $this->fkName($table, $col);
                        if ($fkName) {
                            $alterStmts[] = $this->dialect->dropForeignKeySql($table, $fkName);
                        }
                    }
                    $cascade = $this->driver === 'pgsql' ? ' CASCADE' : '';
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
                $cascade = $this->driver === 'pgsql' ? ' CASCADE' : '';
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
    private function createTableSql(ReflectionClass $ref, string $table): string
    {
        $q    = fn(string $id): string => $this->dialect->quoteIdentifier($id);
        $cols = $this->getColumnDefinitions($ref);
        $defs = implode(",\n  ", $cols);

        // Find primary key via #[Id] attribute, fallback to #[Field(primaryKey:true)]
        $primaryKey = 'id'; // default fallback
        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->getAttributes(IdAttr::class)) {
                $primaryKey = $prop->getName();
                break;
            }
            $attrs = $prop->getAttributes(FieldAttr::class);
            if ($attrs) {
                $attr = $attrs[0]->newInstance();
                if ($attr->primaryKey) {
                    $primaryKey = $prop->getName();
                    break;
                }
            }
        }

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
    private function getColumnDefinitions(ReflectionClass $ref): array
    {
        $q    = fn(string $id): string => $this->dialect->quoteIdentifier($id);
        $defs = [];

        // First pass: detect primary key name and type
        $primaryKeyType = 'int';
        $primaryKeyName = 'id';
        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->getAttributes(IdAttr::class)) {
                $primaryKeyName = $prop->getName();
                $fieldAttrs = $prop->getAttributes(FieldAttr::class);
                if ($fieldAttrs) {
                    $primaryKeyType = $fieldAttrs[0]->newInstance()->type ?? 'int';
                }
                break;
            }
            $attrs = $prop->getAttributes(FieldAttr::class);
            if ($attrs) {
                $attr = $attrs[0]->newInstance();
                if ($attr->primaryKey) {
                    $primaryKeyName = $prop->getName();
                    $primaryKeyType = $attr->type ?? 'int';
                    break;
                }
            }
        }

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

            /* Many-to-One owning side → FK column with correct type */
            if ($prop->getAttributes(ManyToOne::class)) {
                $m2o      = $prop->getAttributes(ManyToOne::class)[0]->newInstance();
                $colName  = str_ends_with($name, '_id') ? $name : $name . '_id';

                // Skip if this FK column is the PK (shared PK/FK pattern);
                // the PK will be emitted by the #[Field] handler below.
                if ($colName === $primaryKeyName) {
                    continue;
                }

                $nullable = $m2o->nullable ? ' NULL' : ' NOT NULL';

                $targetClass  = new ReflectionClass($m2o->targetEntity);
                $targetPkType = 'int';
                foreach ($targetClass->getProperties() as $targetProp) {
                    if ($targetProp->getAttributes(IdAttr::class)) {
                        $fa = $targetProp->getAttributes(FieldAttr::class);
                        $targetPkType = $fa ? ($fa[0]->newInstance()->type ?? 'int') : 'int';
                        break;
                    }
                    $targetAttrs = $targetProp->getAttributes(FieldAttr::class);
                    if ($targetAttrs) {
                        $targetAttr = $targetAttrs[0]->newInstance();
                        if ($targetAttr->primaryKey) {
                            $targetPkType = $targetAttr->type ?? 'int';
                            break;
                        }
                    }
                }

                $fkType = $targetPkType === 'uuid'
                    ? $this->dialect->uuidFkType()
                    : $this->dialect->intFkType();
                $defs[$colName] = "{$q($colName)} {$fkType}{$nullable}";
                continue;
            }

            /* One-to-One owning side → FK column with correct type */
            if ($prop->getAttributes(OneToOne::class)) {
                $o2o = $prop->getAttributes(OneToOne::class)[0]->newInstance();
                if (!$o2o->mappedBy) {
                    $colName  = str_ends_with($name, '_id') ? $name : $name . '_id';

                    // Skip if this FK column is the PK (shared PK/FK pattern)
                    if ($colName === $primaryKeyName) {
                        continue;
                    }

                    $nullable = $o2o->nullable ? ' NULL' : ' NOT NULL';

                    $targetClass  = new ReflectionClass($o2o->targetEntity);
                    $targetPkType = 'int';
                    foreach ($targetClass->getProperties() as $targetProp) {
                        if ($targetProp->getAttributes(IdAttr::class)) {
                            $fa = $targetProp->getAttributes(FieldAttr::class);
                            $targetPkType = $fa ? ($fa[0]->newInstance()->type ?? 'int') : 'int';
                            break;
                        }
                        $targetAttrs = $targetProp->getAttributes(FieldAttr::class);
                        if ($targetAttrs) {
                            $targetAttr = $targetAttrs[0]->newInstance();
                            if ($targetAttr->primaryKey) {
                                $targetPkType = $targetAttr->type ?? 'int';
                                break;
                            }
                        }
                    }

                    $fkType = $targetPkType === 'uuid'
                        ? $this->dialect->uuidFkType()
                        : $this->dialect->intFkType();
                    $defs[$colName] = "{$q($colName)} {$fkType}{$nullable}";
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

                if ($autoInc) {
                    // For PG use SERIAL/BIGSERIAL; for MySQL keep type + AUTO_INCREMENT keyword
                    $sqlBase       = $this->dialect->autoIncrementType($type);
                    $nullSuffix    = $nullable ? ' NULL' : ' NOT NULL';
                    $autoIncSuffix = $this->dialect->autoIncrementKeyword();
                    $defs[$name]   = "{$q($name)} {$sqlBase}{$nullSuffix}{$autoIncSuffix}"
                        . $this->renderDefault($fa->default ?? null, $type);
                } else {
                    $sqlType     = $this->dialect->mapTypeWithNullability($type, $length, $nullable, $enumValues);
                    $defs[$name] = "{$q($name)} {$sqlType}"
                        . $this->renderDefault($fa->default ?? null, $type);
                }
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
                $defs[$name] = "{$q($name)} {$sqlType}{$length}{$nullable}"
                    . $this->renderDefault($attr->default ?? null, $attr->type ?? 'string');
                continue;
            }

            /* Fallback for properties without attributes */
            $type    = $prop->getType()?->getName() ?? 'string';
            $sqlType = $this->dialect->mapTypeWithNullability($type);
            $defs[$name] = "{$q($name)} {$sqlType}";
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
            $cascade = $this->driver === 'pgsql' ? ' CASCADE' : '';
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
    private function isRelation(ReflectionProperty $p): bool
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
