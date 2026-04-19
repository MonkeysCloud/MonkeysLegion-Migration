<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Schema;

use MonkeysLegion\Entity\Attributes\AuditTrail;
use MonkeysLegion\Entity\Attributes\Column as ColumnAttr;
use MonkeysLegion\Entity\Attributes\Entity as EntityAttr;
use MonkeysLegion\Entity\Attributes\Field as FieldAttr;
use MonkeysLegion\Entity\Attributes\Id as IdAttr;
use MonkeysLegion\Entity\Attributes\Index as IndexAttr;
use MonkeysLegion\Entity\Attributes\JoinTable;
use MonkeysLegion\Entity\Attributes\ManyToMany;
use MonkeysLegion\Entity\Attributes\ManyToOne;
use MonkeysLegion\Entity\Attributes\OneToMany;
use MonkeysLegion\Entity\Attributes\OneToOne;
use MonkeysLegion\Entity\Attributes\SoftDeletes;
use MonkeysLegion\Entity\Attributes\Timestamps;
use MonkeysLegion\Entity\Attributes\Uuid as UuidAttr;
use MonkeysLegion\Entity\Attributes\Versioned;
use MonkeysLegion\Entity\Attributes\Virtual;

use ReflectionClass;
use ReflectionProperty;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Builds TableDefinition objects from entity class reflection.
 *
 * Reads all v2 entity attributes (#[Entity], #[Field], #[Id], #[Index],
 * #[Timestamps], #[SoftDeletes], #[AuditTrail], #[Versioned], #[Uuid],
 * #[Virtual], #[Column], relationships) and assembles a complete
 * desired-schema representation.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class EntitySchemaBuilder
{
    // ── Metadata cache ─────────────────────────────────────────────

    /** @var array<class-string, TableDefinition> */
    private array $cache = [];

    /** @var array<string, string> table name → PK type */
    private array $pkTypeCache = [];

    /** @var array<string, string> table name → PK column name */
    private array $pkNameCache = [];

    // ── Public API ─────────────────────────────────────────────────

    /**
     * Build TableDefinition objects for a list of entity FQCNs.
     *
     * @param list<class-string|ReflectionClass<object>> $entities
     *
     * @return array<string, TableDefinition> table name => definition
     */
    public function buildAll(array $entities): array
    {
        // First pass: collect PK info for FK type resolution
        foreach ($entities as $entity) {
            $ref = $entity instanceof ReflectionClass ? $entity : new ReflectionClass($entity);
            $table = $this->resolveTableName($ref);
            $this->collectPrimaryKeyInfo($ref, $table);
        }

        // Second pass: build full table definitions
        $tables = [];
        foreach ($entities as $entity) {
            $ref = $entity instanceof ReflectionClass ? $entity : new ReflectionClass($entity);
            $fqcn = $ref->getName();

            if (isset($this->cache[$fqcn])) {
                $tables[$this->resolveTableName($ref)] = $this->cache[$fqcn];
                continue;
            }

            $definition = $this->buildTable($ref);
            $this->cache[$fqcn] = $definition;
            $tables[$definition->name] = $definition;
        }

        return $tables;
    }

    /**
     * Build a single TableDefinition from an entity class.
     *
     * @param ReflectionClass<object> $ref
     */
    public function buildTable(ReflectionClass $ref): TableDefinition
    {
        $table = $this->resolveTableName($ref);

        // Collect PK info if not cached yet
        if (!isset($this->pkNameCache[$table])) {
            $this->collectPrimaryKeyInfo($ref, $table);
        }

        $columns     = [];
        $indexes     = [];
        $foreignKeys = [];
        $primaryKey  = $this->pkNameCache[$table] ?? 'id';

        // Process class-level attributes
        $this->processTimestamps($ref, $columns);
        $this->processSoftDeletes($ref, $columns);
        $this->processAuditTrail($ref, $columns);
        $this->processClassIndexes($ref, $table, $indexes);

        // Process property-level attributes
        /** @var list<ReflectionProperty> $properties */
        $properties = $ref->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $prop) {
            $propName = $prop->getName();

            // Skip virtual/computed properties
            if ($prop->getAttributes(Virtual::class)) {
                continue;
            }

            // Skip pure inverse relations (OneToMany, mappedBy OneToOne, ManyToMany)
            if ($this->isInverseRelation($prop)) {
                continue;
            }

            // ManyToOne owning side → FK column
            $m2oAttrs = $prop->getAttributes(ManyToOne::class);
            if ($m2oAttrs) {
                $this->processManyToOne(
                    $prop,
                    /** @var ManyToOne */ $m2oAttrs[0]->newInstance(),
                    $table,
                    $primaryKey,
                    $columns,
                    $foreignKeys,
                );
                continue;
            }

            // OneToOne owning side → FK column
            $o2oAttrs = $prop->getAttributes(OneToOne::class);
            if ($o2oAttrs) {
                /** @var OneToOne $o2o */
                $o2o = $o2oAttrs[0]->newInstance();
                if (!$o2o->mappedBy) {
                    $this->processOwningOneToOne(
                        $prop,
                        $o2o,
                        $table,
                        $primaryKey,
                        $columns,
                        $foreignKeys,
                    );
                }
                continue;
            }

            // Scalar #[Field]
            $fieldAttrs = $prop->getAttributes(FieldAttr::class);
            if ($fieldAttrs) {
                /** @var FieldAttr $field */
                $field = $fieldAttrs[0]->newInstance();

                // Resolve effective column name
                $colName = $this->resolveColumnName($prop);

                // Check if #[Versioned] — ensures the column is included
                $isVersioned = (bool) $prop->getAttributes(Versioned::class);
                $comment     = $field->comment ?? ($isVersioned ? 'Optimistic lock version.' : null);

                $columns[$colName] = new ColumnDefinition(
                    name:          $propName,
                    type:          $field->type,
                    length:        $field->length ?? ($field->precision !== null
                        ? "{$field->precision},{$field->scale}"
                        : null),
                    nullable:      $field->nullable,
                    autoIncrement: $field->autoIncrement,
                    primaryKey:    $field->primaryKey || $prop->getAttributes(IdAttr::class) !== [],
                    default:       $field->default,
                    enumValues:    $field->enumValues !== null
                        ? array_values($field->enumValues)
                        : null,
                    unsigned:      $field->unsigned,
                    unique:        $field->unique,
                    comment:       $comment,
                    columnName:    $colName !== $propName ? $colName : null,
                );

                // Property-level #[Index]
                $this->processPropertyIndexes($prop, $table, $colName, $indexes);

                // #[Field(unique: true)] → auto-add unique index
                if ($field->unique) {
                    $idxName = IndexDefinition::generateName($table, [$colName], true);
                    $indexes[] = new IndexDefinition(
                        name:    $idxName,
                        columns: [$colName],
                        unique:  true,
                    );
                }

                continue;
            }

            // #[Column] override without #[Field] (rare)
            $colAttrs = $prop->getAttributes(ColumnAttr::class);
            if ($colAttrs) {
                /** @var ColumnAttr $attr */
                $attr = $colAttrs[0]->newInstance();
                $columns[$attr->name] = new ColumnDefinition(
                    name:       $propName,
                    type:       'string',
                    columnName: $attr->name,
                );
            }
        }

        // Build join tables for ManyToMany
        // (returned separately — caller can merge)

        return new TableDefinition(
            name:        $table,
            columns:     $columns,
            primaryKey:  $primaryKey,
            indexes:     $indexes,
            foreignKeys: $foreignKeys,
        );
    }

    /**
     * Extract ManyToMany join table definitions from entities.
     *
     * @param list<class-string|ReflectionClass<object>> $entities
     *
     * @return array<string, TableDefinition> join table name => definition
     */
    public function buildJoinTables(array $entities): array
    {
        $joinTables = [];

        foreach ($entities as $entity) {
            $ref = $entity instanceof ReflectionClass ? $entity : new ReflectionClass($entity);
            $ownerTable = $this->resolveTableName($ref);

            /** @var list<ReflectionProperty> $props */
            $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);

            foreach ($props as $prop) {
                foreach ($prop->getAttributes(ManyToMany::class) as $attr) {
                    /** @var ManyToMany $meta */
                    $meta = $attr->newInstance();

                    // v2: JoinTable can come from nested ManyToMany param
                    // or from a separate #[JoinTable] attribute on the property
                    $jt = $meta->joinTable;
                    if (!$jt instanceof JoinTable) {
                        $jtAttrs = $prop->getAttributes(JoinTable::class);
                        if ($jtAttrs) {
                            /** @var JoinTable $jt */
                            $jt = $jtAttrs[0]->newInstance();
                        }
                    }

                    if (!$jt instanceof JoinTable) {
                        continue;
                    }

                    // Skip if already processed
                    if (isset($joinTables[$jt->name])) {
                        continue;
                    }

                    $targetTable = $this->snake($meta->targetEntity);

                    $ownerPk  = $this->pkNameCache[$ownerTable] ?? 'id';
                    $targetPk = $this->pkNameCache[$targetTable] ?? 'id';

                    $ownerPkType  = $this->pkTypeCache[$ownerTable] ?? 'int';
                    $targetPkType = $this->pkTypeCache[$targetTable] ?? 'int';

                    $columns = [
                        $jt->joinColumn => new ColumnDefinition(
                            name:     $jt->joinColumn,
                            type:     $this->resolveFkType($ownerPkType),
                            nullable: false,
                        ),
                        $jt->inverseColumn => new ColumnDefinition(
                            name:     $jt->inverseColumn,
                            type:     $this->resolveFkType($targetPkType),
                            nullable: false,
                        ),
                    ];

                    $foreignKeys = [
                        new ForeignKeyDefinition(
                            name:             ForeignKeyDefinition::generateName($jt->name, $jt->joinColumn),
                            column:           $jt->joinColumn,
                            referencedTable:  $ownerTable,
                            referencedColumn: $ownerPk,
                            onDelete:         'CASCADE',
                        ),
                        new ForeignKeyDefinition(
                            name:             ForeignKeyDefinition::generateName($jt->name, $jt->inverseColumn),
                            column:           $jt->inverseColumn,
                            referencedTable:  $targetTable,
                            referencedColumn: $targetPk,
                            onDelete:         'CASCADE',
                        ),
                    ];

                    // Composite PK on both FK columns
                    $joinTables[$jt->name] = new TableDefinition(
                        name:        $jt->name,
                        columns:     $columns,
                        primaryKey:  [$jt->joinColumn, $jt->inverseColumn],
                        foreignKeys: $foreignKeys,
                    );
                }
            }
        }

        return $joinTables;
    }

    // ── Private helpers ────────────────────────────────────────────

    /**
     * Resolve the database table name for an entity class.
     *
     * @param ReflectionClass<object> $ref
     */
    private function resolveTableName(ReflectionClass $ref): string
    {
        $entityAttrs = $ref->getAttributes(EntityAttr::class);

        if ($entityAttrs) {
            /** @var EntityAttr $entity */
            $entity = $entityAttrs[0]->newInstance();
            if ($entity->table !== null) {
                return $entity->table;
            }
        }

        // Default: snake_case of short class name
        return strtolower(
            (string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $ref->getShortName()),
        );
    }

    /**
     * Collect primary key name and type for FK resolution.
     *
     * @param ReflectionClass<object> $ref
     */
    private function collectPrimaryKeyInfo(ReflectionClass $ref, string $table): void
    {
        /** @var list<ReflectionProperty> $properties */
        $properties = $ref->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $prop) {
            // #[Id] attribute
            if ($prop->getAttributes(IdAttr::class)) {
                $this->pkNameCache[$table] = $prop->getName();

                $fieldAttrs = $prop->getAttributes(FieldAttr::class);
                $this->pkTypeCache[$table] = $fieldAttrs
                    ? (/** @var FieldAttr */ $fieldAttrs[0]->newInstance())->type ?? 'int'
                    : 'int';

                // Check if UUID
                if ($prop->getAttributes(UuidAttr::class)) {
                    $this->pkTypeCache[$table] = 'uuid';
                }

                return;
            }

            // Fallback: #[Field(primaryKey: true)]
            $fieldAttrs = $prop->getAttributes(FieldAttr::class);
            if ($fieldAttrs) {
                /** @var FieldAttr $field */
                $field = $fieldAttrs[0]->newInstance();
                if ($field->primaryKey) {
                    $this->pkNameCache[$table] = $prop->getName();
                    $this->pkTypeCache[$table] = $field->type ?? 'int';
                    return;
                }
            }
        }

        // Defaults
        $this->pkNameCache[$table] = 'id';
        $this->pkTypeCache[$table] = 'int';
    }

    /**
     * Process #[Timestamps] class attribute.
     *
     * @param ReflectionClass<object>          $ref
     * @param array<string, ColumnDefinition> &$columns
     */
    private function processTimestamps(ReflectionClass $ref, array &$columns): void
    {
        $attrs = $ref->getAttributes(Timestamps::class);

        if (!$attrs) {
            return;
        }

        /** @var Timestamps $ts */
        $ts = $attrs[0]->newInstance();

        // Only add if not already defined as an explicit #[Field]
        if (!$this->hasFieldAttribute($ref, $ts->createdColumn)) {
            $columns[$ts->createdColumn] = new ColumnDefinition(
                name:     $ts->createdColumn,
                type:     'datetime',
                nullable: true,
                comment:  'Record creation timestamp.',
            );
        }

        if (!$this->hasFieldAttribute($ref, $ts->updatedColumn)) {
            $columns[$ts->updatedColumn] = new ColumnDefinition(
                name:     $ts->updatedColumn,
                type:     'datetime',
                nullable: true,
                comment:  'Last update timestamp.',
            );
        }
    }

    /**
     * Process #[SoftDeletes] class attribute.
     *
     * @param ReflectionClass<object>          $ref
     * @param array<string, ColumnDefinition> &$columns
     */
    private function processSoftDeletes(ReflectionClass $ref, array &$columns): void
    {
        $attrs = $ref->getAttributes(SoftDeletes::class);

        if (!$attrs) {
            return;
        }

        /** @var SoftDeletes $sd */
        $sd = $attrs[0]->newInstance();

        if (!$this->hasFieldAttribute($ref, $sd->column)) {
            $columns[$sd->column] = new ColumnDefinition(
                name:     $sd->column,
                type:     'datetime',
                nullable: true,
                comment:  'Soft-delete timestamp.',
            );
        }
    }

    /**
     * Process #[AuditTrail] class attribute.
     *
     * Adds shadow columns for audit tracking (created_by, updated_by,
     * created_ip, updated_ip). These columns exist in the database
     * but not as PHP properties on the entity.
     *
     * @param ReflectionClass<object>          $ref
     * @param array<string, ColumnDefinition> &$columns
     */
    private function processAuditTrail(ReflectionClass $ref, array &$columns): void
    {
        $attrs = $ref->getAttributes(AuditTrail::class);

        if (!$attrs) {
            return;
        }

        /** @var AuditTrail $audit */
        $audit = $attrs[0]->newInstance();

        // created_by — nullable VARCHAR for user ID or name
        if (!$this->hasFieldAttribute($ref, $audit->createdByColumn)) {
            $columns[$audit->createdByColumn] = new ColumnDefinition(
                name:     $audit->createdByColumn,
                type:     'string',
                length:   255,
                nullable: true,
                comment:  'User who created this record.',
            );
        }

        // updated_by
        if (!$this->hasFieldAttribute($ref, $audit->updatedByColumn)) {
            $columns[$audit->updatedByColumn] = new ColumnDefinition(
                name:     $audit->updatedByColumn,
                type:     'string',
                length:   255,
                nullable: true,
                comment:  'User who last updated this record.',
            );
        }

        // created_ip
        if (!$this->hasFieldAttribute($ref, $audit->createdIpColumn)) {
            $columns[$audit->createdIpColumn] = new ColumnDefinition(
                name:     $audit->createdIpColumn,
                type:     'ipaddress',
                nullable: true,
                comment:  'IP address of the creator.',
            );
        }

        // updated_ip
        if (!$this->hasFieldAttribute($ref, $audit->updatedIpColumn)) {
            $columns[$audit->updatedIpColumn] = new ColumnDefinition(
                name:     $audit->updatedIpColumn,
                type:     'ipaddress',
                nullable: true,
                comment:  'IP address of the last updater.',
            );
        }
    }

    /**
     * Process class-level #[Index] attributes (composite indexes).
     *
     * @param ReflectionClass<object>  $ref
     * @param list<IndexDefinition>   &$indexes
     */
    private function processClassIndexes(
        ReflectionClass $ref,
        string $table,
        array &$indexes,
    ): void {
        foreach ($ref->getAttributes(IndexAttr::class) as $attr) {
            /** @var IndexAttr $idx */
            $idx = $attr->newInstance();

            $name = $idx->name ?? IndexDefinition::generateName(
                $table,
                $idx->columns,
                $idx->unique,
            );

            $indexes[] = new IndexDefinition(
                name:    $name,
                columns: $idx->columns,
                unique:  $idx->unique,
            );
        }
    }

    /**
     * Process property-level #[Index] attributes.
     *
     * @param list<IndexDefinition> &$indexes
     */
    private function processPropertyIndexes(
        ReflectionProperty $prop,
        string $table,
        string $colName,
        array &$indexes,
    ): void {
        foreach ($prop->getAttributes(IndexAttr::class) as $attr) {
            /** @var IndexAttr $idx */
            $idx = $attr->newInstance();

            $columns = $idx->columns ?: [$colName];
            $name    = $idx->name ?? IndexDefinition::generateName(
                $table,
                $columns,
                $idx->unique,
            );

            $indexes[] = new IndexDefinition(
                name:    $name,
                columns: $columns,
                unique:  $idx->unique,
            );
        }
    }

    /**
     * Process a ManyToOne owning side into a FK column + constraint.
     *
     * @param array<string, ColumnDefinition>  &$columns
     * @param list<ForeignKeyDefinition>       &$foreignKeys
     */
    private function processManyToOne(
        ReflectionProperty $prop,
        ManyToOne $m2o,
        string $table,
        string $primaryKey,
        array &$columns,
        array &$foreignKeys,
    ): void {
        $propName = $prop->getName();
        $fkCol    = str_ends_with($propName, '_id') ? $propName : $propName . '_id';

        // Skip shared PK/FK pattern
        if ($fkCol === $primaryKey) {
            return;
        }

        $targetTable = $this->resolveTargetTableName($m2o->targetEntity);
        $targetPk    = $this->pkNameCache[$targetTable] ?? 'id';
        $targetType  = $this->pkTypeCache[$targetTable] ?? 'int';

        $columns[$fkCol] = new ColumnDefinition(
            name:     $fkCol,
            type:     $this->resolveFkType($targetType),
            nullable: $m2o->nullable,
        );

        $onDelete = $m2o->nullable ? 'SET NULL' : 'RESTRICT';

        $foreignKeys[] = new ForeignKeyDefinition(
            name:             ForeignKeyDefinition::generateName($table, $fkCol),
            column:           $fkCol,
            referencedTable:  $targetTable,
            referencedColumn: $targetPk,
            onDelete:         $onDelete,
        );
    }

    /**
     * Process a OneToOne owning side into a FK column + constraint.
     *
     * @param array<string, ColumnDefinition>  &$columns
     * @param list<ForeignKeyDefinition>       &$foreignKeys
     */
    private function processOwningOneToOne(
        ReflectionProperty $prop,
        OneToOne $o2o,
        string $table,
        string $primaryKey,
        array &$columns,
        array &$foreignKeys,
    ): void {
        $propName = $prop->getName();
        $fkCol    = str_ends_with($propName, '_id') ? $propName : $propName . '_id';

        // Skip shared PK/FK pattern
        if ($fkCol === $primaryKey) {
            return;
        }

        $targetTable = $this->resolveTargetTableName($o2o->targetEntity);
        $targetPk    = $this->pkNameCache[$targetTable] ?? 'id';
        $targetType  = $this->pkTypeCache[$targetTable] ?? 'int';

        $columns[$fkCol] = new ColumnDefinition(
            name:     $fkCol,
            type:     $this->resolveFkType($targetType),
            nullable: $o2o->nullable,
        );

        $onDelete = $o2o->nullable ? 'SET NULL' : 'RESTRICT';

        $foreignKeys[] = new ForeignKeyDefinition(
            name:             ForeignKeyDefinition::generateName($table, $fkCol),
            column:           $fkCol,
            referencedTable:  $targetTable,
            referencedColumn: $targetPk,
            onDelete:         $onDelete,
        );
    }

    /**
     * Check whether a property is an inverse (non-owning) relation.
     */
    private function isInverseRelation(ReflectionProperty $prop): bool
    {
        // OneToMany is always inverse
        if ($prop->getAttributes(OneToMany::class)) {
            return true;
        }

        // OneToOne with mappedBy is inverse
        $o2oAttrs = $prop->getAttributes(OneToOne::class);
        if ($o2oAttrs && (/** @var OneToOne */ $o2oAttrs[0]->newInstance())->mappedBy) {
            return true;
        }

        // ManyToMany — handled separately via buildJoinTables()
        if ($prop->getAttributes(ManyToMany::class)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a property (by name) has an explicit #[Field] attribute.
     *
     * @param ReflectionClass<object> $ref
     */
    private function hasFieldAttribute(ReflectionClass $ref, string $propName): bool
    {
        if (!$ref->hasProperty($propName)) {
            return false;
        }

        return $ref->getProperty($propName)->getAttributes(FieldAttr::class) !== [];
    }

    /**
     * Resolve the effective DB column name for a property.
     */
    private function resolveColumnName(ReflectionProperty $prop): string
    {
        $colAttrs = $prop->getAttributes(ColumnAttr::class);

        if ($colAttrs) {
            /** @var ColumnAttr $col */
            $col = $colAttrs[0]->newInstance();

            return $col->name;
        }

        return $prop->getName();
    }

    /**
     * Resolve the target table name from a FQCN.
     *
     * Uses #[Entity(table: ...)] attribute if present on the target class,
     * falling back to snake_case of the short class name.
     */
    private function resolveTargetTableName(string $fqcn): string
    {
        if (class_exists($fqcn)) {
            $ref = new \ReflectionClass($fqcn);
            return $this->resolveTableName($ref);
        }

        // Fallback: snake_case of class name
        return $this->snake($fqcn);
    }

    /**
     * Convert a FQCN or short class name to snake_case.
     */
    private function snake(string $class): string
    {
        $base = str_contains($class, '\\')
            ? substr($class, (int) strrpos($class, '\\') + 1)
            : $class;

        return strtolower(
            (string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $base),
        );
    }

    /**
     * Map a PK field type to the correct FK column type.
     *
     * Must preserve unsigned/signed distinction so MySQL doesn't reject
     * FK constraints due to incompatible column types.
     */
    private function resolveFkType(string $pkType): string
    {
        return match ($pkType) {
            'uuid'          => 'uuid',
            'unsignedBigInt' => 'unsignedBigInt',
            'bigInt'        => 'bigInt',
            'unsignedInt'   => 'unsignedInt',
            default         => $pkType,
        };
    }
}
