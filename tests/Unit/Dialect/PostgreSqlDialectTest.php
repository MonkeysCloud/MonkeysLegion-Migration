<?php

declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Unit\Dialect;

use MonkeysLegion\Migration\Dialect\PostgreSqlDialect;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Migration\Dialect\PostgreSqlDialect
 */
final class PostgreSqlDialectTest extends TestCase
{
    private PostgreSqlDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new PostgreSqlDialect();
    }

    // ─── Identifier quoting ────────────────────────────────────────

    public function testQuoteIdentifierUsesDoubleQuotes(): void
    {
        $this->assertSame('"users"', $this->dialect->quoteIdentifier('users'));
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
            'mediumText'         => ['mediumText', null, 'TEXT'],
            'longText'           => ['longText', null, 'TEXT'],
            'integer'            => ['integer', null, 'INTEGER'],
            'int shorthand'      => ['int', null, 'INTEGER'],
            'bigInt'             => ['bigInt', null, 'BIGINT'],
            'unsignedBigInt'     => ['unsignedBigInt', null, 'BIGINT'],
            'boolean'            => ['boolean', null, 'BOOLEAN'],
            'bool shorthand'     => ['bool', null, 'BOOLEAN'],
            'tinyInt'            => ['tinyInt', null, 'SMALLINT'],
            'date'               => ['date', null, 'DATE'],
            'datetime'           => ['datetime', null, 'TIMESTAMP'],
            'timestamp'          => ['timestamp', null, 'TIMESTAMP'],
            'uuid'               => ['uuid', null, 'UUID'],
            'json'               => ['json', null, 'JSONB'],
            'decimal'            => ['decimal', null, 'NUMERIC(10,2)'],
            'float'              => ['float', null, 'REAL'],
            'binary'             => ['binary', null, 'BYTEA'],
            'ipaddress'          => ['ipaddress', null, 'INET'],
            'macaddress'         => ['macaddress', null, 'MACADDR'],
        ];
    }

    // ─── Spatial types (native PG, no PostGIS) ─────────────────────

    public function testSpatialTypesAreNativePg(): void
    {
        $this->assertSame('BYTEA', $this->dialect->mapType('geometry'));
        $this->assertSame('POINT', $this->dialect->mapType('point'));
        $this->assertSame('PATH', $this->dialect->mapType('linestring'));
        $this->assertSame('POLYGON', $this->dialect->mapType('polygon'));
    }

    // ─── Enum handling ─────────────────────────────────────────────

    public function testEnumMapsToVarcharWithCheck(): void
    {
        $result = $this->dialect->mapType('enum', null, ['draft', 'published']);
        $this->assertStringContainsString('VARCHAR', $result);
    }

    // ─── FK type helpers ───────────────────────────────────────────

    public function testIntFkType(): void
    {
        $this->assertSame('INTEGER', $this->dialect->intFkType());
    }

    public function testUuidFkType(): void
    {
        $this->assertSame('UUID', $this->dialect->uuidFkType());
    }

    // ─── ALTER COLUMN produces separate sub-clauses ────────────────

    public function testAlterColumnSqlSplitsIntoClauses(): void
    {
        $sql = $this->dialect->alterColumnSql(
            'users',
            'name',
            'VARCHAR(100)',
            false,
            ' DEFAULT \'foo\''
        );

        // Must contain ALTER TABLE with quoted identifiers
        $this->assertStringContainsString('ALTER TABLE "users"', $sql);

        // Must have TYPE clause
        $this->assertStringContainsString('ALTER COLUMN "name" TYPE VARCHAR(100)', $sql);

        // Must have NOT NULL clause (nullable=false)
        $this->assertStringContainsString('ALTER COLUMN "name" SET NOT NULL', $sql);

        // Must have DEFAULT clause
        $this->assertStringContainsString('ALTER COLUMN "name" SET DEFAULT', $sql);
    }

    public function testAlterColumnSqlNullableDropsNotNull(): void
    {
        $sql = $this->dialect->alterColumnSql(
            'users',
            'bio',
            'TEXT',
            true,
            ''
        );

        $this->assertStringContainsString('ALTER COLUMN "bio" DROP NOT NULL', $sql);
        // No DEFAULT clause when empty
        $this->assertStringNotContainsString('SET DEFAULT', $sql);
    }

    // ─── FK check toggling returns empty (PG DDL is transactional) ─

    public function testDisableFkChecksReturnsEmpty(): void
    {
        $this->assertSame('', $this->dialect->disableFkChecks());
    }

    public function testEnableFkChecksReturnsEmpty(): void
    {
        $this->assertSame('', $this->dialect->enableFkChecks());
    }

    // ─── Auto-increment uses SERIAL ────────────────────────────────

    public function testAutoIncrementKeywordIsEmpty(): void
    {
        $this->assertSame('', $this->dialect->autoIncrementKeyword());
    }

    public function testAutoIncrementTypeForInt(): void
    {
        $this->assertSame('SERIAL', $this->dialect->autoIncrementType('integer'));
    }

    public function testAutoIncrementTypeForBigInt(): void
    {
        $this->assertSame('BIGSERIAL', $this->dialect->autoIncrementType('bigInt'));
    }

    // ─── Engine suffix is empty ────────────────────────────────────

    public function testEngineSuffixIsEmpty(): void
    {
        $this->assertSame('', $this->dialect->engineSuffix());
    }

    // ─── Drop FK uses DROP CONSTRAINT ──────────────────────────────

    public function testDropForeignKeySql(): void
    {
        $sql = $this->dialect->dropForeignKeySql('orders', 'fk_orders_user_id');
        $this->assertStringContainsString('DROP CONSTRAINT', $sql);
        $this->assertStringContainsString('"orders"', $sql);
    }
}
