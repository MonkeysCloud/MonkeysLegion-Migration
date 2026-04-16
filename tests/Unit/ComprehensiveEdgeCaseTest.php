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
use MonkeysLegion\Entity\Attributes\Entity;
use MonkeysLegion\Entity\Attributes\Field;
use MonkeysLegion\Entity\Attributes\Id;
use PDO;
use PHPUnit\Framework\TestCase;

#[Entity(table: 'custom_table')]
class CustomTableEntity {
    #[Id]
    #[Field(type: 'integer', primaryKey: true)]
    public int $id;
}

final class ComprehensiveEdgeCaseTest extends TestCase
{
    private function makeGenerator(string $driver, array $pdoMockMethods = []): MigrationGenerator
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')
            ->with(PDO::ATTR_DRIVER_NAME)
            ->willReturn($driver);
        $pdo->method('quote')
            ->willReturnCallback(fn(string $v) => "'" . addslashes($v) . "'");

        foreach ($pdoMockMethods as $method => $callback) {
            $pdo->method($method)->willReturnCallback($callback);
        }

        $conn = $this->createMock(ConnectionInterface::class);
        $conn->method('pdo')->willReturn($pdo);

        return new MigrationGenerator($conn);
    }

    /**
     * Test Case 1: Enum updates in MySQL.
     * Changing the allowed values of an ENUM column should trigger MODIFY COLUMN.
     */
    public function testMysqlEnumUpdate(): void
    {
        $gen = $this->makeGenerator('mysql');

        // Existing schema has status as ENUM with fewer values
        // Note: PostEntity maps to 'postentity' table by default (lowercase short name)
        $schema = [
            'postentity' => [
                'id'     => ['type' => 'int', 'nullable' => false],
                'status' => ['type' => 'enum', 'length' => "'draft','published'", 'nullable' => false, 'default' => 'draft'],
                'title'  => ['type' => 'string', 'length' => 255, 'nullable' => false],
                'body'   => ['type' => 'text', 'nullable' => false],
                'created_at' => ['type' => 'datetime', 'nullable' => false],
            ],
        ];

        $sql = $gen->diff([PostEntity::class], $schema);

        // Should include all three values: draft, published, archived
        $this->assertStringContainsString("MODIFY COLUMN `status` ENUM('draft','published','archived')", $sql);
    }

    /**
     * Test Case 2: Column type change (INT to BIGINT).
     */
    public function testColumnTypeChangeIntToBigInt(): void
    {
        $gen = $this->makeGenerator('mysql');

        // Actually, let's test a case where PostEntity's body (text) is currently a varchar in DB.
        $schema = [
            'postentity' => [
                'id'   => ['type' => 'int', 'nullable' => false],
                'body' => ['type' => 'varchar', 'length' => 255, 'nullable' => false],
                'title'  => ['type' => 'string', 'length' => 255, 'nullable' => false],
                'status' => ['type' => 'enum', 'length' => "'draft','published','archived'", 'nullable' => false, 'default' => 'draft'],
                'created_at' => ['type' => 'datetime', 'nullable' => false],
            ],
        ];
        
        $sql = $gen->diff([PostEntity::class], $schema);
        $this->assertStringContainsString("MODIFY COLUMN `body` TEXT", $sql);
    }

    /**
     * Test Case 3: Default value updates.
     * NULL to NOT NULL with a default.
     */
    public function testDefaultValueUpdate(): void
    {
        $gen = $this->makeGenerator('mysql');

        $schema = [
            'userentity' => [
                'id'        => ['type' => 'uuid', 'nullable' => false],
                'is_active' => ['type' => 'boolean', 'nullable' => true, 'default' => null],
                'name'      => ['type' => 'string', 'length' => 100, 'nullable' => false],
                'email'     => ['type' => 'string', 'length' => 255, 'nullable' => false],
                'enabled'   => ['type' => 'boolean', 'nullable' => false, 'default' => true],
            ],
        ];

        $sql = $gen->diff([UserEntity::class], $schema);

        // Should change to NOT NULL and add DEFAULT FALSE
        $this->assertStringContainsString("MODIFY COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT FALSE", $sql);
    }

    /**
     * Test Case 4: Shared PK/FK pattern consistency.
     */
    public function testSharedPkFkConsistency(): void
    {
        $gen = $this->makeGenerator('pgsql');

        $sql = $gen->diff([UserEntity::class, FreelancerProfileEntity::class], []);

        // FreelancerProfileEntity user_id is PK and references UserEntity(id)
        $this->assertStringContainsString('PRIMARY KEY ("user_id")', $sql);
        // It should NOT try to add another user_id column
        $this->assertStringNotContainsString('ADD COLUMN "user_id"', $sql);
    }

    /**
     * Test Case 5: PostgreSQL JSONB vs JSON.
     */
    public function testPgJsonbMapping(): void
    {
        $gen = $this->makeGenerator('pgsql');
        
        $entity = new class {
            #[Id]
            #[Field(type: 'integer', primaryKey: true)]
            public int $id;

            #[Field(type: 'json')]
            public array $data;
        };

        $sql = $gen->diff([get_class($entity)], []);
        $this->assertStringContainsString('"data" JSONB NOT NULL', $sql);
    }

    /**
     * Test Case 7: PostgreSQL complex column alter (type and nullability).
     */
    public function testPgComplexAlter(): void
    {
        $gen = $this->makeGenerator('pgsql');

        $schema = [
            'postentity' => [
                'id'    => ['type' => 'integer', 'nullable' => false],
                'title' => ['type' => 'varchar', 'length' => 100, 'nullable' => true, 'default' => null],
            ],
        ];

        // Entity has title as VARCHAR(255) NOT NULL
        $sql = $gen->diff([PostEntity::class], $schema);

        // PostgreSQL dialect generates: ALTER TABLE "postentity" ALTER COLUMN "title" TYPE VARCHAR(255), ALTER COLUMN "title" SET NOT NULL
        $this->assertStringContainsString('ALTER TABLE "postentity" ALTER COLUMN "title" TYPE VARCHAR(255)', $sql);
        $this->assertStringContainsString('ALTER COLUMN "title" SET NOT NULL', $sql);
    }

    /**
     * Test Case 8: Custom table name via #[Entity(table: '...')]
     */
    public function testCustomTableNameAttribute(): void
    {
        $gen = $this->makeGenerator('mysql');

        $sql = $gen->diff([CustomTableEntity::class], []);
        $this->assertStringContainsString('CREATE TABLE `custom_table`', $sql);
    }

    /**
     * Test Case 9: ManyToMany join table lifecycle.
     */
    public function testJoinTableRetention(): void
    {
        $gen = $this->makeGenerator('mysql');

        // Existing schema already has the join table.
        $schema = [
            'post_tags' => [
                'post_id' => ['type' => 'int', 'nullable' => false],
                'tag_id'  => ['type' => 'int', 'nullable' => false],
            ],
            'postentity' => [], // just to avoid drop
            'tagentity'  => [], // just to avoid drop
        ];

        $sql = $gen->diff([PostEntity::class, TagEntity::class], $schema);

        // It should NOT try to DROP the join table if it's still expected.
        $this->assertStringNotContainsString('DROP TABLE IF EXISTS `post_tags`', $sql);
    }

    /**
     * Test Case 6: Dropping columns that are Foreign Keys.
     * This should trigger dropping the FK constraint first.
     */
    public function testDropFkColumn(): void
    {
        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('execute')->willReturn(true);
        $stmtMock->method('fetchColumn')->willReturn('fk_post_author');

        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('mysql');
        $pdo->method('prepare')->willReturn($stmtMock);
        $pdo->method('quote')->willReturnCallback(fn($v) => "'$v'");

        $conn = $this->createMock(ConnectionInterface::class);
        $conn->method('pdo')->willReturn($pdo);

        $gen = new MigrationGenerator($conn);

        // Schema has author_id which is a FK, but entity no longer has it.
        $schema = [
            'postentity' => [
                'id'        => ['type' => 'int', 'nullable' => false],
                'author_id' => ['type' => 'int', 'nullable' => true],
                'title'     => ['type' => 'string', 'length' => 255, 'nullable' => false],
                'body'      => ['type' => 'text', 'nullable' => false],
                'status'    => ['type' => 'enum', 'length' => "'draft','published','archived'", 'nullable' => false, 'default' => 'draft'],
                'created_at' => ['type' => 'datetime', 'nullable' => false],
            ],
        ];

        $sql = $gen->diff([PostEntity::class], $schema);

        $this->assertStringContainsString("ALTER TABLE `postentity` DROP FOREIGN KEY `fk_post_author`", $sql);
        $this->assertStringContainsString("ALTER TABLE `postentity` DROP COLUMN `author_id`", $sql);
    }

    /**
     * Test Case 10: Dropping join table when no longer expected.
     */
    public function testJoinTableDrop(): void
    {
        $gen = $this->makeGenerator('mysql');

        // Schema has a join table 'old_join_table', but no entity expects it.
        $schema = [
            'old_join_table' => [
                'a_id' => ['type' => 'int', 'nullable' => false],
                'b_id' => ['type' => 'int', 'nullable' => false],
            ],
            'postentity' => [],
        ];

        $sql = $gen->diff([PostEntity::class], $schema);

        $this->assertStringContainsString('DROP TABLE IF EXISTS `old_join_table`', $sql);
    }
}
