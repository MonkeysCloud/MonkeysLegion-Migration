<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Unit\Schema;

use MonkeysLegion\Migration\Schema\ColumnDefinition;
use MonkeysLegion\Migration\Schema\EntitySchemaBuilder;
use MonkeysLegion\Migration\Schema\ForeignKeyDefinition;
use MonkeysLegion\Migration\Schema\IndexDefinition;
use MonkeysLegion\Migration\Schema\TableDefinition;
use MonkeysLegion\Migration\Tests\Fixtures\AuditEntity;
use MonkeysLegion\Migration\Tests\Fixtures\CommentEntity;
use MonkeysLegion\Migration\Tests\Fixtures\FreelancerProfileEntity;
use MonkeysLegion\Migration\Tests\Fixtures\OrderEntity;
use MonkeysLegion\Migration\Tests\Fixtures\PostEntity;
use MonkeysLegion\Migration\Tests\Fixtures\TagEntity;
use MonkeysLegion\Migration\Tests\Fixtures\UserEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(EntitySchemaBuilder::class)]
#[CoversClass(ColumnDefinition::class)]
#[CoversClass(IndexDefinition::class)]
#[CoversClass(ForeignKeyDefinition::class)]
#[CoversClass(TableDefinition::class)]
final class EntitySchemaBuilderTest extends TestCase
{
    private EntitySchemaBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new EntitySchemaBuilder();
    }

    // ── buildTable basic ──────────────────────────────────────────

    public function testBuildTableReturnsTableDefinition(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(PostEntity::class));

        self::assertInstanceOf(TableDefinition::class, $table);
        self::assertSame('post_entity', $table->name);
    }

    public function testBuildTableDetectsAutoIncrementPK(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(PostEntity::class));

        self::assertSame('id', $table->primaryKey);
        self::assertTrue($table->columns['id']->primaryKey);
        self::assertTrue($table->columns['id']->autoIncrement);
    }

    public function testBuildTableDetectsUuidPK(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(UserEntity::class));

        self::assertSame('id', $table->primaryKey);
        self::assertSame('uuid', $table->columns['id']->type);
        self::assertTrue($table->columns['id']->primaryKey);
    }

    public function testBuildTableIncludesAllScalarFields(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(PostEntity::class));

        self::assertArrayHasKey('title', $table->columns);
        self::assertArrayHasKey('body', $table->columns);
        self::assertArrayHasKey('status', $table->columns);
        self::assertArrayHasKey('created_at', $table->columns);
    }

    // ── #[Entity(table: ...)] override ────────────────────────────

    public function testCustomTableNameFromEntityAttribute(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(OrderEntity::class));

        self::assertSame('orders', $table->name);
    }

    // ── Enum fields ───────────────────────────────────────────────

    public function testEnumFieldContainsValues(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(PostEntity::class));

        self::assertSame('enum', $table->columns['status']->type);
        self::assertSame(['draft', 'published', 'archived'], $table->columns['status']->enumValues);
        self::assertSame('draft', $table->columns['status']->default);
    }

    // ── Decimal/precision ─────────────────────────────────────────

    public function testDecimalFieldHasPrecision(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(OrderEntity::class));

        self::assertArrayHasKey('total', $table->columns);
        self::assertSame('decimal', $table->columns['total']->type);
        self::assertSame('10,2', $table->columns['total']->length);
    }

    // ── Nullable fields ───────────────────────────────────────────

    public function testNullableFieldsAreFlagged(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(FreelancerProfileEntity::class));

        self::assertTrue($table->columns['headline']->nullable);
        self::assertTrue($table->columns['bio']->nullable);
        self::assertTrue($table->columns['hourly_rate']->nullable);
    }

    public function testNonNullableFieldsDefaultToFalse(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(PostEntity::class));

        self::assertFalse($table->columns['title']->nullable);
    }

    // ── Default values ────────────────────────────────────────────

    public function testDefaultValuesArePreserved(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(UserEntity::class));

        self::assertFalse($table->columns['is_active']->default);
        self::assertTrue($table->columns['enabled']->default);
    }

    // ── #[Column(name: ...)] rename ───────────────────────────────

    public function testColumnNameOverride(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(OrderEntity::class));

        self::assertArrayHasKey('order_number', $table->columns);
        self::assertSame('orderNumber', $table->columns['order_number']->name);
        self::assertSame('order_number', $table->columns['order_number']->columnName);
    }

    // ── #[Timestamps] ─────────────────────────────────────────────

    public function testTimestampsAddsColumns(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(OrderEntity::class));

        self::assertArrayHasKey('created_at', $table->columns);
        self::assertArrayHasKey('updated_at', $table->columns);
        self::assertTrue($table->columns['created_at']->nullable);
        self::assertTrue($table->columns['updated_at']->nullable);
    }

    // ── #[SoftDeletes] ────────────────────────────────────────────

    public function testSoftDeletesAddsColumn(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(OrderEntity::class));

        self::assertArrayHasKey('deleted_at', $table->columns);
        self::assertTrue($table->columns['deleted_at']->nullable);
    }

    // ── #[AuditTrail] ─────────────────────────────────────────────

    public function testAuditTrailAddsShadowColumns(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(AuditEntity::class));

        self::assertArrayHasKey('created_by', $table->columns);
        self::assertArrayHasKey('updated_by', $table->columns);
        self::assertArrayHasKey('created_ip', $table->columns);
        self::assertArrayHasKey('updated_ip', $table->columns);
        self::assertTrue($table->columns['created_by']->nullable);
        self::assertTrue($table->columns['updated_by']->nullable);
    }

    // ── #[Versioned] ──────────────────────────────────────────────

    public function testVersionedFieldHasComment(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(AuditEntity::class));

        self::assertArrayHasKey('version', $table->columns);
        self::assertSame('integer', $table->columns['version']->type);
    }

    // ── #[Virtual] — skipped properties ───────────────────────────

    public function testVirtualPropertiesAreSkipped(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(AuditEntity::class));

        self::assertArrayNotHasKey('computedLabel', $table->columns);
        self::assertArrayNotHasKey('computed_label', $table->columns);
    }

    // ── ManyToOne FK ──────────────────────────────────────────────

    public function testManyToOneCreatesForeignKey(): void
    {
        // Pre-build target tables so PK info is cached
        $this->builder->buildAll([
            UserEntity::class,
            PostEntity::class,
            CommentEntity::class,
        ]);

        $table = $this->builder->buildTable(new ReflectionClass(CommentEntity::class));

        self::assertArrayHasKey('post_id', $table->columns);
        self::assertArrayHasKey('author_id', $table->columns);

        $fkNames = array_map(
            static fn(ForeignKeyDefinition $fk): string => $fk->column,
            $table->foreignKeys,
        );
        self::assertContains('post_id', $fkNames);
        self::assertContains('author_id', $fkNames);
    }

    public function testManyToOneFkColumnMatchesTargetPkType(): void
    {
        $this->builder->buildAll([
            UserEntity::class,
            CommentEntity::class,
        ]);

        $table = $this->builder->buildTable(new ReflectionClass(CommentEntity::class));

        // User has UUID PK → FK should be uuid
        self::assertSame('uuid', $table->columns['author_id']->type);
    }

    // ── OneToOne owning side ──────────────────────────────────────

    public function testOneToOneSharedPKIsNotDuplicated(): void
    {
        $this->builder->buildAll([
            UserEntity::class,
            FreelancerProfileEntity::class,
        ]);

        $table = $this->builder->buildTable(new ReflectionClass(FreelancerProfileEntity::class));

        // user_id is both PK and FK name → should NOT create user_id_id
        self::assertArrayNotHasKey('user_id_id', $table->columns);
    }

    // ── OneToMany is inverse-only ─────────────────────────────────

    public function testOneToManyDoesNotCreateColumn(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(UserEntity::class));

        self::assertArrayNotHasKey('comments', $table->columns);
    }

    // ── ManyToMany JoinTable ──────────────────────────────────────

    public function testBuildJoinTablesCreatesJoinTable(): void
    {
        $this->builder->buildAll([PostEntity::class, TagEntity::class]);
        $joinTables = $this->builder->buildJoinTables([TagEntity::class]);

        self::assertArrayHasKey('post_tags', $joinTables);
        $jt = $joinTables['post_tags'];

        self::assertArrayHasKey('tag_id', $jt->columns);
        self::assertArrayHasKey('post_id', $jt->columns);
        self::assertCount(2, $jt->foreignKeys);
    }

    public function testJoinTableFkReferencesCorrectTables(): void
    {
        $this->builder->buildAll([PostEntity::class, TagEntity::class]);
        $joinTables = $this->builder->buildJoinTables([TagEntity::class]);

        $fkTables = array_map(
            static fn(ForeignKeyDefinition $fk): string => $fk->referencedTable,
            $joinTables['post_tags']->foreignKeys,
        );

        self::assertContains('tag_entity', $fkTables);
        self::assertContains('post_entity', $fkTables);
    }

    // ── #[Index] class-level ──────────────────────────────────────

    public function testClassLevelIndexIsCreated(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(OrderEntity::class));

        $indexNames = array_map(
            static fn(IndexDefinition $idx): string => $idx->name,
            $table->indexes,
        );

        self::assertContains('idx_orders_user_status', $indexNames);
    }

    // ── #[Field(unique: true)] auto-index ─────────────────────────

    public function testUniqueFieldCreatesUniqueIndex(): void
    {
        $table = $this->builder->buildTable(new ReflectionClass(OrderEntity::class));

        $uniqueIndexes = array_filter(
            $table->indexes,
            static fn(IndexDefinition $idx): bool => $idx->unique,
        );

        self::assertNotEmpty($uniqueIndexes, 'Unique index should be created for unique field');
    }

    // ── buildAll ──────────────────────────────────────────────────

    public function testBuildAllReturnsAllTables(): void
    {
        $tables = $this->builder->buildAll([
            UserEntity::class,
            PostEntity::class,
            CommentEntity::class,
        ]);

        self::assertCount(3, $tables);
        self::assertArrayHasKey('user_entity', $tables);
        self::assertArrayHasKey('post_entity', $tables);
        self::assertArrayHasKey('comment_entity', $tables);
    }

    public function testBuildAllAcceptsReflectionClassInstances(): void
    {
        $tables = $this->builder->buildAll([
            new ReflectionClass(UserEntity::class),
        ]);

        self::assertArrayHasKey('user_entity', $tables);
    }

    // ── buildAll caching ──────────────────────────────────────────

    public function testBuildAllCachesPkInfo(): void
    {
        // First build caches PK info
        $this->builder->buildAll([UserEntity::class, PostEntity::class]);

        // Second build of dependent entity should use cached PK info
        $table = $this->builder->buildTable(new ReflectionClass(CommentEntity::class));

        // If PK cache works, author_id resolves to 'uuid' (from UserEntity)
        self::assertSame('uuid', $table->columns['author_id']->type);
    }

    // ── Edge cases ────────────────────────────────────────────────

    public function testEmptyEntityListReturnsEmptyArray(): void
    {
        self::assertSame([], $this->builder->buildAll([]));
    }

    public function testNoJoinTablesReturnsEmptyArray(): void
    {
        self::assertSame([], $this->builder->buildJoinTables([]));
    }

    // ── snake_case resolution ─────────────────────────────────────

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function tableNameProvider(): array
    {
        return [
            'UserEntity'              => [UserEntity::class, 'user_entity'],
            'PostEntity'              => [PostEntity::class, 'post_entity'],
            'CommentEntity'           => [CommentEntity::class, 'comment_entity'],
            'FreelancerProfileEntity' => [FreelancerProfileEntity::class, 'freelancer_profile_entity'],
            'OrderEntity (custom)'    => [OrderEntity::class, 'orders'],
        ];
    }

    #[DataProvider('tableNameProvider')]
    public function testTableNameResolution(string $class, string $expected): void
    {
        $table = $this->builder->buildTable(new ReflectionClass($class));

        self::assertSame($expected, $table->name);
    }
}
