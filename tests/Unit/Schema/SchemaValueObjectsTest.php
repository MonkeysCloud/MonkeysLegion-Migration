<?php

declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Unit\Schema;

use MonkeysLegion\Migration\Schema\ColumnDefinition;
use MonkeysLegion\Migration\Schema\ForeignKeyDefinition;
use MonkeysLegion\Migration\Schema\IndexDefinition;
use MonkeysLegion\Migration\Schema\TableDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 */
#[CoversClass(\MonkeysLegion\Migration\Schema\ColumnDefinition::class)]
#[CoversClass(\MonkeysLegion\Migration\Schema\IndexDefinition::class)]
#[CoversClass(\MonkeysLegion\Migration\Schema\ForeignKeyDefinition::class)]
#[CoversClass(\MonkeysLegion\Migration\Schema\TableDefinition::class)]
final class SchemaValueObjectsTest extends TestCase
{
    // ── ColumnDefinition ───────────────────────────────────────────

    public function testColumnEffectiveNameUsesName(): void
    {
        $col = new ColumnDefinition(name: 'email', type: 'string');
        $this->assertSame('email', $col->effectiveName);
    }

    public function testColumnEffectiveNameUsesColumnName(): void
    {
        $col = new ColumnDefinition(name: 'email', type: 'string', columnName: 'user_email');
        $this->assertSame('user_email', $col->effectiveName);
    }

    public function testColumnWith(): void
    {
        $col = new ColumnDefinition(name: 'age', type: 'int', nullable: false);
        $modified = $col->with(['nullable' => true, 'default' => 18]);

        $this->assertFalse($col->nullable);
        $this->assertTrue($modified->nullable);
        $this->assertSame(18, $modified->default);
        $this->assertSame('age', $modified->name);
    }

    public function testColumnDefaults(): void
    {
        $col = new ColumnDefinition(name: 'x', type: 'string');

        $this->assertNull($col->length);
        $this->assertFalse($col->nullable);
        $this->assertFalse($col->autoIncrement);
        $this->assertFalse($col->primaryKey);
        $this->assertNull($col->default);
        $this->assertNull($col->enumValues);
        $this->assertFalse($col->unsigned);
        $this->assertFalse($col->unique);
        $this->assertNull($col->comment);
        $this->assertNull($col->columnName);
    }

    // ── IndexDefinition ────────────────────────────────────────────

    public function testIndexGenerateNameNormal(): void
    {
        $name = IndexDefinition::generateName('users', ['email']);
        $this->assertSame('idx_users_email', $name);
    }

    public function testIndexGenerateNameUnique(): void
    {
        $name = IndexDefinition::generateName('users', ['email'], true);
        $this->assertSame('uniq_users_email', $name);
    }

    public function testIndexGenerateNameComposite(): void
    {
        $name = IndexDefinition::generateName('orders', ['user_id', 'status']);
        $this->assertSame('idx_orders_user_id_status', $name);
    }

    public function testIndexGenerateNameTruncatesLongNames(): void
    {
        $columns = ['very_long_column_name_one', 'very_long_column_name_two'];
        $name = IndexDefinition::generateName('extremely_long_table_name', $columns);

        $this->assertLessThanOrEqual(64, strlen($name));
    }

    // ── ForeignKeyDefinition ───────────────────────────────────────

    public function testFkGenerateNameNormal(): void
    {
        $name = ForeignKeyDefinition::generateName('posts', 'user_id');
        $this->assertSame('fk_posts_user_id', $name);
    }

    public function testFkGenerateNameTruncatesLongNames(): void
    {
        $name = ForeignKeyDefinition::generateName(
            'extremely_long_table_name_here',
            'another_extremely_long_column_name_foreign',
        );
        $this->assertLessThanOrEqual(64, strlen($name));
    }

    public function testFkDefaults(): void
    {
        $fk = new ForeignKeyDefinition(
            name: 'fk_test',
            column: 'user_id',
            referencedTable: 'users',
            referencedColumn: 'id',
        );

        $this->assertSame('RESTRICT', $fk->onDelete);
        $this->assertSame('RESTRICT', $fk->onUpdate);
    }

    // ── TableDefinition ────────────────────────────────────────────

    public function testTableHasColumn(): void
    {
        $table = new TableDefinition(
            name: 'users',
            columns: [
                'id'    => new ColumnDefinition(name: 'id', type: 'int'),
                'email' => new ColumnDefinition(name: 'email', type: 'string'),
            ],
        );

        $this->assertTrue($table->hasColumn('id'));
        $this->assertTrue($table->hasColumn('email'));
        $this->assertFalse($table->hasColumn('phone'));
    }

    public function testTableGetColumn(): void
    {
        $emailCol = new ColumnDefinition(name: 'email', type: 'string');
        $table = new TableDefinition(
            name: 'users',
            columns: ['email' => $emailCol],
        );

        $this->assertSame($emailCol, $table->getColumn('email'));
        $this->assertNull($table->getColumn('phone'));
    }

    public function testTableGetColumnNames(): void
    {
        $table = new TableDefinition(
            name: 'users',
            columns: [
                'id'    => new ColumnDefinition(name: 'id', type: 'int'),
                'email' => new ColumnDefinition(name: 'email', type: 'string'),
            ],
        );

        $this->assertSame(['id', 'email'], $table->getColumnNames());
    }

    public function testTableGetIndexNames(): void
    {
        $table = new TableDefinition(
            name: 'users',
            columns: [],
            indexes: [
                new IndexDefinition(name: 'idx_email', columns: ['email']),
                new IndexDefinition(name: 'idx_name', columns: ['name']),
            ],
        );

        $this->assertSame(['idx_email', 'idx_name'], $table->getIndexNames());
    }

    public function testTableGetIndex(): void
    {
        $idx = new IndexDefinition(name: 'idx_email', columns: ['email'], unique: true);
        $table = new TableDefinition(
            name: 'users',
            columns: [],
            indexes: [$idx],
        );

        $this->assertSame($idx, $table->getIndex('idx_email'));
        $this->assertNull($table->getIndex('idx_missing'));
    }

    public function testTableGetForeignKey(): void
    {
        $fk = new ForeignKeyDefinition(
            name: 'fk_posts_user',
            column: 'user_id',
            referencedTable: 'users',
            referencedColumn: 'id',
        );
        $table = new TableDefinition(
            name: 'posts',
            columns: [],
            foreignKeys: [$fk],
        );

        $this->assertSame($fk, $table->getForeignKey('fk_posts_user'));
        $this->assertNull($table->getForeignKey('fk_missing'));
    }
}
