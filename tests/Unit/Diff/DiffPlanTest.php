<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Unit\Diff;

use MonkeysLegion\Migration\Diff\ColumnChange;
use MonkeysLegion\Migration\Diff\DiffPlan;
use MonkeysLegion\Migration\Diff\TableDiff;
use MonkeysLegion\Migration\Schema\ColumnDefinition;
use MonkeysLegion\Migration\Schema\ForeignKeyDefinition;
use MonkeysLegion\Migration\Schema\IndexDefinition;
use MonkeysLegion\Migration\Schema\TableDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiffPlan::class)]
#[CoversClass(TableDiff::class)]
#[CoversClass(ColumnChange::class)]
final class DiffPlanTest extends TestCase
{
    // ── DiffPlan::isEmpty ─────────────────────────────────────────

    public function testEmptyPlanIsEmpty(): void
    {
        $plan = new DiffPlan();
        self::assertTrue($plan->isEmpty());
    }

    public function testPlanWithCreateTablesIsNotEmpty(): void
    {
        $plan = new DiffPlan(
            createTables: [
                new TableDefinition(
                    name:    'users',
                    columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
                ),
            ],
        );

        self::assertFalse($plan->isEmpty());
    }

    public function testPlanWithDropTablesIsNotEmpty(): void
    {
        $plan = new DiffPlan(dropTables: ['old_table']);
        self::assertFalse($plan->isEmpty());
    }

    public function testPlanWithAlterTablesIsNotEmpty(): void
    {
        $diff = new TableDiff('users');
        $diff->addedColumns[] = new ColumnDefinition(name: 'email', type: 'string');

        $plan = new DiffPlan(alterTables: [$diff]);
        self::assertFalse($plan->isEmpty());
    }

    // ── DiffPlan::changeCount ─────────────────────────────────────

    public function testChangeCountZeroForEmptyPlan(): void
    {
        self::assertSame(0, (new DiffPlan())->changeCount());
    }

    public function testChangeCountAggregatesAllChanges(): void
    {
        $diff = new TableDiff('users');
        $diff->addedColumns[]   = new ColumnDefinition(name: 'email', type: 'string');
        $diff->droppedColumns[] = 'old_col';
        $diff->addedIndexes[]   = new IndexDefinition(name: 'idx', columns: ['email']);

        $plan = new DiffPlan(
            createTables: [
                new TableDefinition(
                    name:    'posts',
                    columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
                ),
            ],
            alterTables: [$diff],
            dropTables:  ['legacy_table'],
        );

        // 1 create + 1 drop + 1 added col + 1 dropped col + 1 index = 5
        self::assertGreaterThanOrEqual(5, $plan->changeCount());
    }

    // ── DiffPlan::toHumanReadable ─────────────────────────────────

    public function testToHumanReadableContainsTableNames(): void
    {
        $diff = new TableDiff('users');
        $diff->addedColumns[] = new ColumnDefinition(name: 'phone', type: 'string');

        $plan = new DiffPlan(
            createTables: [
                new TableDefinition(
                    name:    'posts',
                    columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
                ),
            ],
            alterTables: [$diff],
            dropTables:  ['old_table'],
        );

        $output = $plan->toHumanReadable();

        self::assertStringContainsString('posts', $output);
        self::assertStringContainsString('users', $output);
        self::assertStringContainsString('old_table', $output);
    }

    public function testToHumanReadableEmptyPlan(): void
    {
        $output = (new DiffPlan())->toHumanReadable();

        self::assertStringContainsString('up to date', $output);
    }

    // ── TableDiff ─────────────────────────────────────────────────

    public function testTableDiffTableName(): void
    {
        $diff = new TableDiff('orders');
        self::assertSame('orders', $diff->tableName);
    }

    public function testTableDiffTracksAllChangeTypes(): void
    {
        $diff = new TableDiff('users');

        // Added columns
        $diff->addedColumns[] = new ColumnDefinition(name: 'phone', type: 'string');

        // Modified columns
        $diff->modifiedColumns[] = new ColumnChange(
            columnName: 'name',
            from:       new ColumnDefinition(name: 'name', type: 'string', length: 50),
            to:         new ColumnDefinition(name: 'name', type: 'string', length: 255),
        );

        // Dropped columns
        $diff->droppedColumns[] = 'legacy_field';

        // Added indexes
        $diff->addedIndexes[] = new IndexDefinition(
            name:    'idx_users_phone',
            columns: ['phone'],
        );

        // Dropped indexes
        $diff->droppedIndexes[] = 'idx_old';

        // Added FKs
        $diff->addedForeignKeys[] = new ForeignKeyDefinition(
            name:             'fk_users_org_id',
            column:           'org_id',
            referencedTable:  'orgs',
            referencedColumn: 'id',
        );

        // Dropped FKs
        $diff->droppedForeignKeys[] = 'fk_legacy';

        self::assertCount(1, $diff->addedColumns);
        self::assertCount(1, $diff->modifiedColumns);
        self::assertCount(1, $diff->droppedColumns);
        self::assertCount(1, $diff->addedIndexes);
        self::assertCount(1, $diff->droppedIndexes);
        self::assertCount(1, $diff->addedForeignKeys);
        self::assertCount(1, $diff->droppedForeignKeys);
    }

    // ── ColumnChange ──────────────────────────────────────────────

    public function testColumnChangeCapturesFromAndTo(): void
    {
        $from = new ColumnDefinition(name: 'status', type: 'string', length: 20);
        $to   = new ColumnDefinition(name: 'status', type: 'enum', enumValues: ['a', 'b']);

        $change = new ColumnChange(
            columnName: 'status',
            from:       $from,
            to:         $to,
        );

        self::assertSame('status', $change->columnName);
        self::assertSame('string', $change->from->type);
        self::assertSame('enum', $change->to->type);
    }
}
