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
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Migration\MigrationGenerator
 */
final class MigrationGeneratorTest extends TestCase
{
    /**
     * Create a MigrationGenerator wired to an in-memory SQLite PDO pretending
     * to be the given driver.
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
    // Bug 1: Boolean false default renders DEFAULT FALSE (not empty)
    // ═══════════════════════════════════════════════════════════════

    public function testBooleanFalseDefaultRendersDefaultFalse(): void
    {
        $sql = $this->diffForEntities('pgsql', [UserEntity::class]);

        // is_active has default: false
        $this->assertStringContainsString('DEFAULT FALSE', $sql);
    }

    public function testBooleanTrueDefaultRendersDefaultTrue(): void
    {
        $sql = $this->diffForEntities('pgsql', [UserEntity::class]);

        // enabled has default: true
        $this->assertStringContainsString('DEFAULT TRUE', $sql);
    }

    public function testMysqlBooleanDefaultRendersNumeric(): void
    {
        $sql = $this->diffForEntities('mysql', [UserEntity::class]);

        // MySQL booleans also render TRUE/FALSE now
        $this->assertStringContainsString('DEFAULT FALSE', $sql);
        $this->assertStringContainsString('DEFAULT TRUE', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // Bug 3: No duplicate ALTER TABLE ADD COLUMN after CREATE TABLE
    // ═══════════════════════════════════════════════════════════════

    public function testNewTableDoesNotProduceDuplicateAlters(): void
    {
        $sql = $this->diffForEntities('pgsql', [UserEntity::class]);

        // "name" should appear once in CREATE TABLE, not also as ALTER TABLE ADD COLUMN
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringNotContainsString('ADD COLUMN', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // Bug 4: FK columns don't get double _id suffix
    // ═══════════════════════════════════════════════════════════════

    public function testFkColumnNoDoubleIdSuffix(): void
    {
        $sql = $this->diffForEntities('pgsql', [
            UserEntity::class,
            PostEntity::class,
            CommentEntity::class,
        ]);

        // CommentEntity has ManyToOne to PostEntity (property: $post → post_id)
        // and to UserEntity (property: $author → author_id)
        $this->assertStringNotContainsString('post_id_id', $sql);
        $this->assertStringNotContainsString('author_id_id', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // Bug 5: No double semicolons after CREATE TABLE
    // ═══════════════════════════════════════════════════════════════

    public function testNoDoubleSemicolonsInOutput(): void
    {
        $sql = $this->diffForEntities('pgsql', [UserEntity::class]);

        $this->assertStringNotContainsString(';;', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // PK detection via #[Id] — non-"id" primary key
    // ═══════════════════════════════════════════════════════════════

    public function testNonIdPrimaryKeyInCreateTable(): void
    {
        $sql = $this->diffForEntities('pgsql', [
            UserEntity::class,
            FreelancerProfileEntity::class,
        ]);

        // FreelancerProfileEntity PK is "user_id", not "id"
        $this->assertStringContainsString('PRIMARY KEY ("user_id")', $sql);
    }

    public function testNonIdPrimaryKeyColumnIsUuid(): void
    {
        $sql = $this->diffForEntities('pgsql', [
            UserEntity::class,
            FreelancerProfileEntity::class,
        ]);

        // The CREATE TABLE for freelancerprofileentity should have "user_id" UUID NOT NULL
        // Not INTEGER NULL (which would be a FK-generated column)
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

        // "user_id" INTEGER NULL should NOT appear (that's the FK pattern)
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

        // Join table post_tags should reference both tables with their correct PK ("id")
        $this->assertStringContainsString('REFERENCES "tagentity"("id")', $sql);
        $this->assertStringContainsString('REFERENCES "post_entity"("id")', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // PostgreSQL dialect specifics
    // ═══════════════════════════════════════════════════════════════

    public function testPgCreateTableUsesUuidType(): void
    {
        $sql = $this->diffForEntities('pgsql', [UserEntity::class]);

        // UserEntity.id should be UUID, not CHAR(36)
        $this->assertMatchesRegularExpression('/"id"\s+UUID/', $sql);
    }

    public function testPgCreateTableUsesBooleanType(): void
    {
        $sql = $this->diffForEntities('pgsql', [UserEntity::class]);

        $this->assertStringContainsString('BOOLEAN', $sql);
        // Should NOT contain TINYINT (that's MySQL)
        $this->assertStringNotContainsString('TINYINT', $sql);
    }

    public function testPgNoFkCheckWrappers(): void
    {
        $sql = $this->diffForEntities('pgsql', [UserEntity::class]);

        // No session_replication_role or FK check toggles
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

        // PG doesn't have native ENUM DDL — uses VARCHAR with CHECK
        $this->assertStringContainsString('VARCHAR', $sql);
        // Should have the default value
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

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS "post_tags"', $sql);
        $this->assertStringContainsString('"tag_id"', $sql);
        $this->assertStringContainsString('"post_id"', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // Unsupported driver
    // ═══════════════════════════════════════════════════════════════

    public function testUnsupportedDriverThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported PDO driver');

        $this->makeGenerator('sqlite');
    }

    // ═══════════════════════════════════════════════════════════════
    // Existing table diff (ALTER TABLE for modified columns)
    // ═══════════════════════════════════════════════════════════════

    public function testExistingTableAddsNewColumn(): void
    {
        $gen = $this->makeGenerator('pgsql');

        // Simulate existing schema with only 'id' and 'name'
        $schema = [
            'userentity' => [
                'id'   => ['Field' => 'id', 'type' => 'uuid', 'nullable' => false, 'default' => null],
                'name' => ['Field' => 'name', 'type' => 'string', 'length' => 100, 'nullable' => false, 'default' => null],
            ],
        ];

        $sql = $gen->diff([UserEntity::class], $schema);

        // Should add the missing columns (email, is_active, enabled) but not name/id
        $this->assertStringContainsString('ADD COLUMN "email"', $sql);
        $this->assertStringContainsString('ADD COLUMN "is_active"', $sql);
        $this->assertStringNotContainsString('ADD COLUMN "id"', $sql);
        $this->assertStringNotContainsString('ADD COLUMN "name"', $sql);
    }

    // ═══════════════════════════════════════════════════════════════
    // DROP unused columns
    // ═══════════════════════════════════════════════════════════════

    public function testDropsUnusedColumns(): void
    {
        $gen = $this->makeGenerator('pgsql');

        // Schema has an extra column 'legacy_field' not in entity
        $schema = [
            'userentity' => [
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

        // Schema has a table not in entities
        $schema = [
            'userentity'   => [
                'id'   => ['Field' => 'id', 'type' => 'uuid'],
                'name' => ['Field' => 'name', 'type' => 'string', 'length' => 100],
                'email' => ['Field' => 'email', 'type' => 'string', 'length' => 255],
                'is_active' => ['Field' => 'is_active', 'type' => 'boolean'],
                'enabled' => ['Field' => 'enabled', 'type' => 'boolean'],
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
            'userentity' => [
                'id' => ['Field' => 'id', 'type' => 'uuid'],
                'name' => ['Field' => 'name', 'type' => 'string', 'length' => 100],
                'email' => ['Field' => 'email', 'type' => 'string', 'length' => 255],
                'is_active' => ['Field' => 'is_active', 'type' => 'boolean'],
                'enabled' => ['Field' => 'enabled', 'type' => 'boolean'],
            ],
            'migrations' => [
                'id' => ['Field' => 'id', 'type' => 'int'],
            ],
        ];

        $sql = $gen->diff([UserEntity::class], $schema);

        $this->assertStringNotContainsString('DROP TABLE IF EXISTS "migrations"', $sql);
    }
}
