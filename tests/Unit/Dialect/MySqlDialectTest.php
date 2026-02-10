<?php

declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Unit\Dialect;

use MonkeysLegion\Migration\Dialect\MySqlDialect;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Migration\Dialect\MySqlDialect
 */
final class MySqlDialectTest extends TestCase
{
    private MySqlDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new MySqlDialect();
    }

    // ─── Identifier quoting ────────────────────────────────────────

    public function testQuoteIdentifierUsesBackticks(): void
    {
        $this->assertSame('`users`', $this->dialect->quoteIdentifier('users'));
    }

    // ─── Type mapping ──────────────────────────────────────────────

    /**
     * @dataProvider typeProvider
     */
    public function testMapType(string $type, ?int $length, string $expected): void
    {
        $this->assertSame($expected, $this->dialect->mapType($type, $length));
    }

    public static function typeProvider(): array
    {
        return [
            'string default'     => ['string', null, 'VARCHAR(255)'],
            'string with length' => ['string', 100, 'VARCHAR(100)'],
            'text'               => ['text', null, 'TEXT'],
            'mediumText'         => ['mediumText', null, 'MEDIUMTEXT'],
            'longText'           => ['longText', null, 'LONGTEXT'],
            'integer'            => ['integer', null, 'INT'],
            'int shorthand'      => ['int', null, 'INT'],
            'bigInt'             => ['bigInt', null, 'BIGINT'],
            'unsignedBigInt'     => ['unsignedBigInt', null, 'BIGINT UNSIGNED'],
            'boolean'            => ['boolean', null, 'TINYINT(1)'],
            'bool shorthand'     => ['bool', null, 'TINYINT(1)'],
            'tinyInt'            => ['tinyInt', null, 'TINYINT(1)'],
            'date'               => ['date', null, 'DATE'],
            'datetime'           => ['datetime', null, 'DATETIME'],
            'timestamp'          => ['timestamp', null, 'TIMESTAMP'],
            'uuid'               => ['uuid', null, 'CHAR(36)'],
            'json'               => ['json', null, 'JSON'],
            'decimal'            => ['decimal', null, 'DECIMAL(10,2)'],
            'float'              => ['float', null, 'FLOAT'],
            'binary'             => ['binary', null, 'BLOB'],
        ];
    }

    public function testMapTypeWithNullability(): void
    {
        $nullable    = $this->dialect->mapTypeWithNullability('string', 100, true);
        $notNullable = $this->dialect->mapTypeWithNullability('string', 100, false);

        $this->assertStringContainsString('NULL', $nullable);
        $this->assertStringContainsString('NOT NULL', $notNullable);
    }

    public function testEnumTypes(): void
    {
        $result = $this->dialect->mapType('enum', null, ['a', 'b', 'c']);
        $this->assertStringContainsString('ENUM', $result);
        $this->assertStringContainsString("'a'", $result);
    }

    // ─── FK type helpers ───────────────────────────────────────────

    public function testIntFkType(): void
    {
        $this->assertSame('INT', $this->dialect->intFkType());
    }

    public function testUuidFkType(): void
    {
        $this->assertSame('CHAR(36)', $this->dialect->uuidFkType());
    }

    // ─── ALTER COLUMN / FK checks ──────────────────────────────────

    public function testAlterColumnSqlProducesModifyColumn(): void
    {
        $sql = $this->dialect->alterColumnSql('users', 'name', 'VARCHAR(100)', false, ' DEFAULT \'foo\'');

        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('MODIFY COLUMN', $sql);
        $this->assertStringContainsString('`name`', $sql);
        $this->assertStringContainsString('VARCHAR(100)', $sql);
        $this->assertStringContainsString('NOT NULL', $sql);
        $this->assertStringContainsString("DEFAULT 'foo'", $sql);
    }

    public function testDisableFkChecksReturnsSetStatement(): void
    {
        $this->assertStringContainsString('FOREIGN_KEY_CHECKS=0', $this->dialect->disableFkChecks());
    }

    public function testEnableFkChecksReturnsSetStatement(): void
    {
        $this->assertStringContainsString('FOREIGN_KEY_CHECKS=1', $this->dialect->enableFkChecks());
    }

    // ─── Auto-increment ────────────────────────────────────────────

    public function testAutoIncrementKeyword(): void
    {
        $this->assertSame(' AUTO_INCREMENT', $this->dialect->autoIncrementKeyword());
    }

    public function testAutoIncrementTypeForInt(): void
    {
        $this->assertSame('INT', $this->dialect->autoIncrementType('integer'));
    }

    public function testAutoIncrementTypeForBigInt(): void
    {
        $this->assertSame('BIGINT', $this->dialect->autoIncrementType('bigInt'));
    }

    // ─── Engine suffix ─────────────────────────────────────────────

    public function testEngineSuffix(): void
    {
        $this->assertSame(' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', $this->dialect->engineSuffix());
    }

    // ─── Drop FK ───────────────────────────────────────────────────

    public function testDropForeignKeySql(): void
    {
        $sql = $this->dialect->dropForeignKeySql('orders', 'fk_orders_user_id');
        $this->assertStringContainsString('DROP FOREIGN KEY', $sql);
        $this->assertStringContainsString('`orders`', $sql);
    }
}
