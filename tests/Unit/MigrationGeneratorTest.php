<?php

declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Unit;

use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Migration\MigrationGenerator;
use MonkeysLegion\Migration\Tests\Fixtures\CommentEntity;
use MonkeysLegion\Migration\Tests\Fixtures\FreelancerProfileEntity;
use MonkeysLegion\Migration\Tests\Fixtures\PostEntity;
use MonkeysLegion\Migration\Tests\Fixtures\TagEntity;
use MonkeysLegion\Migration\Tests\Fixtures\UserEntity;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 */
#[CoversClass(\MonkeysLegion\Migration\MigrationGenerator::class)]
final class MigrationGeneratorTest extends TestCase
{
    /**
     * Create a MigrationGenerator wired to a mock PDO for the given driver.
     */
    private function makeGenerator(string $driver): MigrationGenerator
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')
            ->with(PDO::ATTR_DRIVER_NAME)
            ->willReturn($driver);
        $pdo->method('quote')
            ->willReturnCallback(fn(string $v) => "'" . addslashes($v) . "'");

        $conn = $this->createMock(ConnectionInterface::class);
        $conn->method('pdo')->willReturn($pdo);

        return new MigrationGenerator($conn);
    }

    /**
     * Helper: generate diff SQL for the given entities against an empty schema.
     */
    private function diffForEntities(string $driver, array $entities): string
    {
        $gen = $this->makeGenerator($driver);
        return $gen->diff($entities, []);
    }

    // ═══════════════════════════════════════════════════════════════
    // Boolean defaults
    // ═══════════════════════════════════════════════════════════════

    public function testBooleanFalseDefaultRendersDefaultFalse(): void
    {
        $sql = $this->diffForEntities('pgsql', [UserEntity::class]);

        $this->assertStringContainsString('DEFAULT FALSE', $sql);
    }

    public function testBooleanTrueDefaultRendersDefaultTrue(): void
    {
        $sql = $this->diffForEntities('pgsql', [UserEntity::class]);

        $this->assertStringContainsString('DEFAULT TRUE', $sql);
    }

    public function testMysqlBooleanDefaultRendersNumeric(): void
    {
        $sql = $this->diffForEntities('mysql', [UserEntity::class]);

        $this->assertStringContainsString('DEFAULT FALSE', $sql);
        $this->assertStringContainsString('DEFAULT TRUE', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // No duplicate ALTER TABLE ADD COLUMN after CREATE TABLE
    // ═══════════════════════════════════════════════════════════════

    public function testNewTableDoesNotProduceDuplicateAlters(): void
    {
        $sql = $this->diffForEntities('pgsql', [UserEntity::class]);

        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringNotContainsString('ADD COLUMN', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // FK columns don't get double _id suffix
    // ═══════════════════════════════════════════════════════════════

    public function testFkColumnNoDoubleIdSuffix(): void
    {
        $sql = $this->diffForEntities('pgsql', [
            UserEntity::class,
            PostEntity::class,
            CommentEntity::class,
        ]);

        $this->assertStringNotContainsString('post_id_id', $sql);
        $this->assertStringNotContainsString('author_id_id', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // No double semicolons
    // ═══════════════════════════════════════════════════════════════

    public function testNoDoubleSemicolonsInOutput(): void
    {
        $sql = $this->diffForEntities('pgsql', [UserEntity::class]);

        $this->assertStringNotContainsString(';;', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // PK detection via #[Id]
    // ═══════════════════════════════════════════════════════════════

    public function testNonIdPrimaryKeyInCreateTable(): void
    {
        $sql = $this->diffForEntities('pgsql', [
            UserEntity::class,
            FreelancerProfileEntity::class,
        ]);

        $this->assertStringContainsString('PRIMARY KEY ("user_id")', $sql);
    }

    public function testNonIdPrimaryKeyColumnIsUuid(): void
    {
        $sql = $this->diffForEntities('pgsql', [
            UserEntity::class,
            FreelancerProfileEntity::class,
        ]);

        $this->assertMatchesRegularExpression(
            '/"user_id"\s+UUID\s+NOT NULL/',
            $sql,
        );
    }

    public function testSharedPkFkDoesNotGenerateDuplicateFkColumn(): void
    {
        $sql = $this->diffForEntities('pgsql', [
            UserEntity::class,
            FreelancerProfileEntity::class,
        ]);

        $this->assertDoesNotMatchRegularExpression(
            '/"user_id"\s+INTEGER\s+NULL/',
            $sql,
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // FK REFERENCES uses correct PK column name
    // ═══════════════════════════════════════════════════════════════

    public function testJoinTableFkReferencesUseCorrectPkColumn(): void
    {
        $sql = $this->diffForEntities('pgsql', [
            PostEntity::class,
            TagEntity::class,
        ]);

        // v2: table names are snake_case of class short name
        $this->assertStringContainsString('REFERENCES "tag_entity"("id")', $sql);
        $this->assertStringContainsString('REFERENCES "post_entity"("id")', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // PostgreSQL dialect specifics
    // ═══════════════════════════════════════════════════════════════

    public function testPgCreateTableUsesUuidType(): void
    {
        $sql = $this->diffForEntities('pgsql', [UserEntity::class]);

        $this->assertMatchesRegularExpression('/"id"\s+UUID/', $sql);
    }

    public function testPgCreateTableUsesBooleanType(): void
    {
        $sql = $this->diffForEntities('pgsql', [UserEntity::class]);

        $this->assertStringContainsString('BOOLEAN', $sql);
        $this->assertStringNotContainsString('TINYINT', $sql);
    }

    public function testPgNoFkCheckWrappers(): void
    {
        $sql = $this->diffForEntities('pgsql', [UserEntity::class]);

        $this->assertStringNotContainsString('session_replication_role', $sql);
        $this->assertStringNotContainsString('FOREIGN_KEY_CHECKS', $sql);
    }

    public function testPgCreateTableNoEngineSuffix(): void
    {
        $sql = $this->diffForEntities('pgsql', [UserEntity::class]);

        $this->assertStringNotContainsString('ENGINE=', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // MySQL dialect specifics
    // ═══════════════════════════════════════════════════════════════

    public function testMysqlCreateTableUsesChar36ForUuid(): void
    {
        $sql = $this->diffForEntities('mysql', [UserEntity::class]);

        $this->assertMatchesRegularExpression('/`id`\s+CHAR\(36\)/', $sql);
    }

    public function testMysqlCreateTableUsesEngineSuffix(): void
    {
        $sql = $this->diffForEntities('mysql', [UserEntity::class]);

        $this->assertStringContainsString('ENGINE=InnoDB', $sql);
    }

    public function testMysqlCreateTableUsesTinyintForBool(): void
    {
        $sql = $this->diffForEntities('mysql', [UserEntity::class]);

        $this->assertStringContainsString('TINYINT(1)', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // Auto-increment PK
    // ═══════════════════════════════════════════════════════════════

    public function testPgAutoIncrementUsesSerial(): void
    {
        $sql = $this->diffForEntities('pgsql', [PostEntity::class]);

        $this->assertMatchesRegularExpression('/"id"\s+SERIAL/', $sql);
    }

    public function testMysqlAutoIncrementUsesKeyword(): void
    {
        $sql = $this->diffForEntities('mysql', [PostEntity::class]);

        $this->assertStringContainsString('AUTO_INCREMENT', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // Enum type
    // ═══════════════════════════════════════════════════════════════

    public function testPgEnumUsesVarchar(): void
    {
        $sql = $this->diffForEntities('pgsql', [PostEntity::class]);

        $this->assertStringContainsString('VARCHAR', $sql);
        $this->assertStringContainsString("'draft'", $sql);
    }

    public function testMysqlEnumUsesNativeEnum(): void
    {
        $sql = $this->diffForEntities('mysql', [PostEntity::class]);

        $this->assertStringContainsString('ENUM(', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // Join tables (ManyToMany)
    // ═══════════════════════════════════════════════════════════════

    public function testJoinTableIsCreated(): void
    {
        $sql = $this->diffForEntities('pgsql', [
            PostEntity::class,
            TagEntity::class,
        ]);

        // v2: CREATE TABLE (not IF NOT EXISTS — handled by v2 renderer)
        $this->assertStringContainsString('CREATE TABLE "post_tags"', $sql);
        $this->assertStringContainsString('"tag_id"', $sql);
        $this->assertStringContainsString('"post_id"', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // SQLite is now a valid driver (v2)
    // ═══════════════════════════════════════════════════════════════

    public function testSqliteDriverIsSupported(): void
    {
        $gen = $this->makeGenerator('sqlite');
        $sql = $gen->diff([UserEntity::class], []);

        $this->assertStringContainsString('CREATE TABLE', $sql);
    }

    public function testUnsupportedDriverThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported PDO driver');

        $this->makeGenerator('mssql');
    }

    // ═══════════════════════════════════════════════════════════════
    // Existing table diff (ALTER TABLE for modified columns)
    // ═══════════════════════════════════════════════════════════════

    public function testExistingTableAddsNewColumn(): void
    {
        $gen = $this->makeGenerator('pgsql');

        // v2: table name is 'user_entity' (snake_case of class short name)
        $schema = [
            'user_entity' => [
                'id'   => ['Field' => 'id', 'type' => 'uuid', 'nullable' => false, 'default' => null],
                'name' => ['Field' => 'name', 'type' => 'string', 'length' => 100, 'nullable' => false, 'default' => null],
            ],
        ];

        $sql = $gen->diff([UserEntity::class], $schema);

        $this->assertStringContainsString('ADD COLUMN', $sql);
        $this->assertStringContainsString('"email"', $sql);
        $this->assertStringNotContainsString('ADD COLUMN "id"', $sql);
        $this->assertStringNotContainsString('ADD COLUMN "name"', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // DROP unused columns
    // ═══════════════════════════════════════════════════════════════

    public function testDropsUnusedColumns(): void
    {
        $gen = $this->makeGenerator('pgsql');

        $schema = [
            'user_entity' => [
                'id'           => ['Field' => 'id', 'type' => 'uuid', 'nullable' => false, 'default' => null],
                'name'         => ['Field' => 'name', 'type' => 'string', 'length' => 100, 'nullable' => false, 'default' => null],
                'email'        => ['Field' => 'email', 'type' => 'string', 'length' => 255, 'nullable' => false, 'default' => null],
                'is_active'    => ['Field' => 'is_active', 'type' => 'boolean', 'nullable' => false, 'default' => null],
                'enabled'      => ['Field' => 'enabled', 'type' => 'boolean', 'nullable' => false, 'default' => null],
                'legacy_field' => ['Field' => 'legacy_field', 'type' => 'string', 'length' => 255, 'nullable' => true, 'default' => null],
            ],
        ];

        $sql = $gen->diff([UserEntity::class], $schema);

        $this->assertStringContainsString('DROP COLUMN "legacy_field"', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // DROP unused tables
    // ═══════════════════════════════════════════════════════════════

    public function testDropsUnusedTables(): void
    {
        $gen = $this->makeGenerator('pgsql');

        $schema = [
            'user_entity'    => [
                'id'        => ['Field' => 'id', 'type' => 'uuid'],
                'name'      => ['Field' => 'name', 'type' => 'string', 'length' => 100],
                'email'     => ['Field' => 'email', 'type' => 'string', 'length' => 255],
                'is_active' => ['Field' => 'is_active', 'type' => 'boolean'],
                'enabled'   => ['Field' => 'enabled', 'type' => 'boolean'],
            ],
            'obsolete_table' => [
                'id' => ['Field' => 'id', 'type' => 'int'],
            ],
        ];

        $sql = $gen->diff([UserEntity::class], $schema);

        $this->assertStringContainsString('DROP TABLE IF EXISTS "obsolete_table"', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // Protected tables are never dropped
    // ═══════════════════════════════════════════════════════════════

    public function testMigrationsTableIsNeverDropped(): void
    {
        $gen = $this->makeGenerator('pgsql');

        $schema = [
            'user_entity' => [
                'id'        => ['Field' => 'id', 'type' => 'uuid'],
                'name'      => ['Field' => 'name', 'type' => 'string', 'length' => 100],
                'email'     => ['Field' => 'email', 'type' => 'string', 'length' => 255],
                'is_active' => ['Field' => 'is_active', 'type' => 'boolean'],
                'enabled'   => ['Field' => 'enabled', 'type' => 'boolean'],
            ],
            'migrations' => [
                'id' => ['Field' => 'id', 'type' => 'int'],
            ],
        ];

        $sql = $gen->diff([UserEntity::class], $schema);

        $this->assertStringNotContainsString('DROP TABLE IF EXISTS "migrations"', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // DROP table behaviour
    // ═══════════════════════════════════════════════════════════════

    public function testPgDropTableExists(): void
    {
        $gen = $this->makeGenerator('pgsql');

        $schema = [
            'user_entity' => [
                'id'        => ['Field' => 'id', 'type' => 'uuid'],
                'name'      => ['Field' => 'name', 'type' => 'string', 'length' => 100],
                'email'     => ['Field' => 'email', 'type' => 'string', 'length' => 255],
                'is_active' => ['Field' => 'is_active', 'type' => 'boolean'],
                'enabled'   => ['Field' => 'enabled', 'type' => 'boolean'],
            ],
            'jobs' => [
                'id' => ['Field' => 'id', 'type' => 'int'],
            ],
        ];

        $sql = $gen->diff([UserEntity::class], $schema);

        $this->assertStringContainsString('DROP TABLE IF EXISTS "jobs"', $sql);
    }

    public function testMysqlDropTableDoesNotUseCascade(): void
    {
        $gen = $this->makeGenerator('mysql');

        $schema = [
            'user_entity' => [
                'id'        => ['Field' => 'id', 'type' => 'uuid'],
                'name'      => ['Field' => 'name', 'type' => 'string', 'length' => 100],
                'email'     => ['Field' => 'email', 'type' => 'string', 'length' => 255],
                'is_active' => ['Field' => 'is_active', 'type' => 'boolean'],
                'enabled'   => ['Field' => 'enabled', 'type' => 'boolean'],
            ],
            'jobs' => [
                'id' => ['Field' => 'id', 'type' => 'int'],
            ],
        ];

        $sql = $gen->diff([UserEntity::class], $schema);

        $this->assertStringContainsString('DROP TABLE IF EXISTS `jobs`', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // DROP column behaviour
    // ═══════════════════════════════════════════════════════════════

    public function testPgDropColumn(): void
    {
        $gen = $this->makeGenerator('pgsql');

        $schema = [
            'user_entity' => [
                'id'           => ['Field' => 'id', 'type' => 'uuid', 'nullable' => false, 'default' => null],
                'name'         => ['Field' => 'name', 'type' => 'string', 'length' => 100, 'nullable' => false, 'default' => null],
                'email'        => ['Field' => 'email', 'type' => 'string', 'length' => 255, 'nullable' => false, 'default' => null],
                'is_active'    => ['Field' => 'is_active', 'type' => 'boolean', 'nullable' => false, 'default' => null],
                'enabled'      => ['Field' => 'enabled', 'type' => 'boolean', 'nullable' => false, 'default' => null],
                'old_column'   => ['Field' => 'old_column', 'type' => 'string', 'length' => 255, 'nullable' => true, 'default' => null],
            ],
        ];

        $sql = $gen->diff([UserEntity::class], $schema);

        $this->assertStringContainsString('DROP COLUMN "old_column"', $sql);
    }

    public function testMysqlDropColumnDoesNotUseCascade(): void
    {
        $gen = $this->makeGenerator('mysql');

        $schema = [
            'user_entity' => [
                'id'           => ['Field' => 'id', 'type' => 'uuid', 'nullable' => false, 'default' => null],
                'name'         => ['Field' => 'name', 'type' => 'string', 'length' => 100, 'nullable' => false, 'default' => null],
                'email'        => ['Field' => 'email', 'type' => 'string', 'length' => 255, 'nullable' => false, 'default' => null],
                'is_active'    => ['Field' => 'is_active', 'type' => 'boolean', 'nullable' => false, 'default' => null],
                'enabled'      => ['Field' => 'enabled', 'type' => 'boolean', 'nullable' => false, 'default' => null],
                'old_column'   => ['Field' => 'old_column', 'type' => 'string', 'length' => 255, 'nullable' => true, 'default' => null],
            ],
        ];

        $sql = $gen->diff([UserEntity::class], $schema);

        $this->assertStringContainsString('DROP COLUMN `old_column`', $sql);
        $this->assertStringNotContainsString('CASCADE', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // Statement ordering: DROPs come after CREATEs/ALTERs
    // ═══════════════════════════════════════════════════════════════

    public function testDropStatementsAppearAfterCreates(): void
    {
        $gen = $this->makeGenerator('pgsql');

        $schema = [
            'obsolete_table' => [
                'id' => ['Field' => 'id', 'type' => 'int'],
            ],
        ];

        $sql = $gen->diff([UserEntity::class], $schema);

        $createPos = strpos($sql, 'CREATE TABLE');
        $dropPos   = strpos($sql, 'DROP TABLE');

        $this->assertNotFalse($createPos);
        $this->assertNotFalse($dropPos);
        $this->assertGreaterThan($createPos, $dropPos, 'DROP TABLE should appear after CREATE TABLE');
    }

    // ═══════════════════════════════════════════════════════════════
    // v2: computeDiff returns DiffPlan
    // ═══════════════════════════════════════════════════════════════

    public function testComputeDiffReturnsDiffPlan(): void
    {
        $gen  = $this->makeGenerator('pgsql');
        $plan = $gen->computeDiff([UserEntity::class], []);

        $this->assertFalse($plan->isEmpty());
        $this->assertCount(1, $plan->createTables);
        $this->assertSame('user_entity', $plan->createTables[0]->name);
    }

    public function testComputeDiffEmptyWhenSchemaMatches(): void
    {
        $gen = $this->makeGenerator('pgsql');

        $schema = [
            'user_entity' => [
                'id'        => ['Field' => 'id', 'type' => 'uuid', 'nullable' => false, 'default' => null],
                'name'      => ['Field' => 'name', 'type' => 'string', 'length' => 100, 'nullable' => false, 'default' => null],
                'email'     => ['Field' => 'email', 'type' => 'string', 'length' => 255, 'nullable' => false, 'default' => null],
                'is_active' => ['Field' => 'is_active', 'type' => 'boolean', 'nullable' => false, 'default' => null],
                'enabled'   => ['Field' => 'enabled', 'type' => 'boolean', 'nullable' => false, 'default' => null],
            ],
        ];

        $plan = $gen->computeDiff([UserEntity::class], $schema);

        // Plan may have alter diffs for defaults, but no creates/drops
        $this->assertEmpty($plan->createTables);
        $this->assertEmpty($plan->dropTables);
    }
}
