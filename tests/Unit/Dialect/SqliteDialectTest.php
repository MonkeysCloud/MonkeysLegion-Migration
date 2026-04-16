<?php

declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Unit\Dialect;

use InvalidArgumentException;
use MonkeysLegion\Migration\Dialect\SqliteDialect;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 */
#[CoversClass(\MonkeysLegion\Migration\Dialect\SqliteDialect::class)]
final class SqliteDialectTest extends TestCase
{
    private SqliteDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new SqliteDialect();
    }

    // ── Identifier quoting ─────────────────────────────────────────

    public function testQuoteIdentifier(): void
    {
        $this->assertSame('"users"', $this->dialect->quoteIdentifier('users'));
    }

    public function testQuoteIdentifierRejectsUnsafeIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->dialect->quoteIdentifier('users;DROP');
    }

    // ── Type mapping ───────────────────────────────────────────────

    public function testStringMapsToText(): void
    {
        $this->assertSame('TEXT', $this->dialect->mapType('string'));
    }

    public function testIntMapsToInteger(): void
    {
        $this->assertSame('INTEGER', $this->dialect->mapType('int'));
    }

    public function testBoolMapsToInteger(): void
    {
        $this->assertSame('INTEGER', $this->dialect->mapType('boolean'));
    }

    public function testDecimalMapsToReal(): void
    {
        $this->assertSame('REAL', $this->dialect->mapType('decimal'));
    }

    public function testJsonMapsToText(): void
    {
        $this->assertSame('TEXT', $this->dialect->mapType('json'));
    }

    public function testUuidMapsToText(): void
    {
        $this->assertSame('TEXT', $this->dialect->mapType('uuid'));
    }

    public function testDatetimeMapsToText(): void
    {
        $this->assertSame('TEXT', $this->dialect->mapType('datetime'));
    }

    public function testBinaryMapsToBlob(): void
    {
        $this->assertSame('BLOB', $this->dialect->mapType('binary'));
    }

    public function testEnumMapsToText(): void
    {
        $this->assertSame('TEXT', $this->dialect->mapType('enum'));
    }

    // ── Nullability ────────────────────────────────────────────────

    public function testNullableType(): void
    {
        $result = $this->dialect->mapTypeWithNullability('string', nullable: true);
        $this->assertSame('TEXT NULL', $result);
    }

    public function testNotNullType(): void
    {
        $result = $this->dialect->mapTypeWithNullability('int', nullable: false);
        $this->assertSame('INTEGER NOT NULL', $result);
    }

    // ── Table DDL ──────────────────────────────────────────────────

    public function testNoEngineSuffix(): void
    {
        $this->assertSame('', $this->dialect->engineSuffix());
    }

    // ── Auto-increment ─────────────────────────────────────────────

    public function testAutoIncrementKeyword(): void
    {
        $this->assertSame('', $this->dialect->autoIncrementKeyword());
    }

    public function testAutoIncrementType(): void
    {
        $this->assertSame('INTEGER', $this->dialect->autoIncrementType('int'));
        $this->assertSame('INTEGER', $this->dialect->autoIncrementType('bigint'));
    }

    // ── FK operations ──────────────────────────────────────────────

    public function testDropForeignKeyNotSupported(): void
    {
        $result = $this->dialect->dropForeignKeySql('posts', 'fk_user');
        $this->assertStringContainsString('SQLite', $result);
        $this->assertStringContainsString('not supported', $result);
    }

    public function testUuidFkType(): void
    {
        $this->assertSame('TEXT', $this->dialect->uuidFkType());
    }

    public function testIntFkType(): void
    {
        $this->assertSame('INTEGER', $this->dialect->intFkType());
    }

    // ── FK check toggling ──────────────────────────────────────────

    public function testDisableFkChecks(): void
    {
        $this->assertStringContainsString('PRAGMA', $this->dialect->disableFkChecks());
        $this->assertStringContainsString('OFF', $this->dialect->disableFkChecks());
    }

    public function testEnableFkChecks(): void
    {
        $this->assertStringContainsString('PRAGMA', $this->dialect->enableFkChecks());
        $this->assertStringContainsString('ON', $this->dialect->enableFkChecks());
    }

    // ── Column operations ──────────────────────────────────────────

    public function testAlterColumnNotSupported(): void
    {
        $result = $this->dialect->alterColumnSql('users', 'email', 'TEXT', false, '');
        $this->assertStringContainsString('SQLite', $result);
        $this->assertStringContainsString('not supported', $result);
    }

    public function testRenameColumn(): void
    {
        $result = $this->dialect->renameColumnSql('users', 'old_name', 'new_name');
        $this->assertSame(
            'ALTER TABLE "users" RENAME COLUMN "old_name" TO "new_name"',
            $result,
        );
    }

    // ── Index operations ───────────────────────────────────────────

    public function testDropIndex(): void
    {
        $result = $this->dialect->dropIndexSql('users', 'idx_email');
        $this->assertSame('DROP INDEX IF EXISTS "idx_email"', $result);
    }

    // ── Transaction support ────────────────────────────────────────

    public function testSupportsTransactionalDdl(): void
    {
        $this->assertTrue($this->dialect->supportsTransactionalDdl());
    }

    // ── Table comment ──────────────────────────────────────────────

    public function testTableCommentReturnsEmpty(): void
    {
        $this->assertSame('', $this->dialect->tableCommentSql('users', 'test'));
    }
}
