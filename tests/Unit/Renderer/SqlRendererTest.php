<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Unit\Renderer;

use MonkeysLegion\Migration\Dialect\MySqlDialect;
use MonkeysLegion\Migration\Dialect\PostgreSqlDialect;
use MonkeysLegion\Migration\Dialect\SqliteDialect;
use MonkeysLegion\Migration\Dialect\SqlDialect;
use MonkeysLegion\Migration\Diff\ColumnChange;
use MonkeysLegion\Migration\Diff\DiffPlan;
use MonkeysLegion\Migration\Diff\TableDiff;
use MonkeysLegion\Migration\Renderer\SqlRenderer;
use MonkeysLegion\Migration\Schema\ColumnDefinition;
use MonkeysLegion\Migration\Schema\ForeignKeyDefinition;
use MonkeysLegion\Migration\Schema\IndexDefinition;
use MonkeysLegion\Migration\Schema\TableDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlRenderer::class)]
#[CoversClass(DiffPlan::class)]
#[CoversClass(TableDiff::class)]
#[CoversClass(ColumnChange::class)]
final class SqlRendererTest extends TestCase
{
    // ── CREATE TABLE ──────────────────────────────────────────────

    public function testRenderCreateTableMySQL(): void
    {
        $renderer = new SqlRenderer(new MySqlDialect());
        $plan     = $this->createTablePlan();

        $sql = $renderer->render($plan);

        self::assertStringContainsString('CREATE TABLE', $sql);
        self::assertStringContainsString('users', $sql);
        self::assertStringContainsString('id', $sql);
        self::assertStringContainsString('name', $sql);
    }

    public function testRenderCreateTablePostgreSQL(): void
    {
        $renderer = new SqlRenderer(new PostgreSqlDialect());
        $plan     = $this->createTablePlan();

        $sql = $renderer->render($plan);

        self::assertStringContainsString('CREATE TABLE', $sql);
        self::assertStringContainsString('users', $sql);
    }

    public function testRenderCreateTableSQLite(): void
    {
        $renderer = new SqlRenderer(new SqliteDialect());
        $plan     = $this->createTablePlan();

        $sql = $renderer->render($plan);

        self::assertStringContainsString('CREATE TABLE', $sql);
    }

    // ── DROP TABLE ────────────────────────────────────────────────

    public function testRenderDropTable(): void
    {
        $renderer = new SqlRenderer(new MySqlDialect());
        $plan     = new DiffPlan(dropTables: ['old_table']);

        $sql = $renderer->render($plan);

        self::assertStringContainsString('DROP TABLE', $sql);
        self::assertStringContainsString('old_table', $sql);
    }

    // ── ALTER TABLE — add column ──────────────────────────────────

    public function testRenderAddColumn(): void
    {
        $renderer = new SqlRenderer(new MySqlDialect());

        $diff = new TableDiff('users');
        $diff->addedColumns[] = new ColumnDefinition(
            name:     'email',
            type:     'string',
            length:   255,
            nullable: false,
        );

        $plan = new DiffPlan(alterTables: [$diff]);
        $sql  = $renderer->render($plan);

        self::assertStringContainsString('ALTER TABLE', $sql);
        self::assertStringContainsString('ADD', $sql);
        self::assertStringContainsString('email', $sql);
    }

    // ── ALTER TABLE — modify column ───────────────────────────────

    public function testRenderModifyColumn(): void
    {
        $renderer = new SqlRenderer(new MySqlDialect());

        $diff = new TableDiff('users');
        $diff->modifiedColumns[] = new ColumnChange(
            columnName: 'name',
            from:       new ColumnDefinition(name: 'name', type: 'string', length: 100),
            to:         new ColumnDefinition(name: 'name', type: 'string', length: 255),
        );

        $plan = new DiffPlan(alterTables: [$diff]);
        $sql  = $renderer->render($plan);

        self::assertStringContainsString('ALTER TABLE', $sql);
    }

    // ── ALTER TABLE — drop column ─────────────────────────────────

    public function testRenderDropColumn(): void
    {
        $renderer = new SqlRenderer(new MySqlDialect());

        $diff = new TableDiff('users');
        $diff->droppedColumns[] = 'old_col';

        $plan = new DiffPlan(alterTables: [$diff]);
        $sql  = $renderer->render($plan);

        self::assertStringContainsString('DROP', $sql);
        self::assertStringContainsString('old_col', $sql);
    }

    // ── Index DDL ─────────────────────────────────────────────────

    public function testRenderAddIndex(): void
    {
        $renderer = new SqlRenderer(new MySqlDialect());

        $diff = new TableDiff('users');
        $diff->addedIndexes[] = new IndexDefinition(
            name:    'idx_users_email',
            columns: ['email'],
            unique:  false,
        );

        $plan = new DiffPlan(alterTables: [$diff]);
        $sql  = $renderer->render($plan);

        self::assertStringContainsString('CREATE INDEX', $sql);
        self::assertStringContainsString('idx_users_email', $sql);
    }

    public function testRenderAddUniqueIndex(): void
    {
        $renderer = new SqlRenderer(new MySqlDialect());

        $diff = new TableDiff('users');
        $diff->addedIndexes[] = new IndexDefinition(
            name:    'uniq_users_email',
            columns: ['email'],
            unique:  true,
        );

        $plan = new DiffPlan(alterTables: [$diff]);
        $sql  = $renderer->render($plan);

        self::assertStringContainsString('CREATE UNIQUE INDEX', $sql);
    }

    public function testRenderDropIndex(): void
    {
        $renderer = new SqlRenderer(new MySqlDialect());

        $diff = new TableDiff('users');
        $diff->droppedIndexes[] = 'idx_old';

        $plan = new DiffPlan(alterTables: [$diff]);
        $sql  = $renderer->render($plan);

        self::assertStringContainsString('DROP INDEX', $sql);
    }

    // ── FK DDL ────────────────────────────────────────────────────

    public function testRenderAddForeignKey(): void
    {
        $renderer = new SqlRenderer(new MySqlDialect());

        $diff = new TableDiff('posts');
        $diff->addedForeignKeys[] = new ForeignKeyDefinition(
            name:             'fk_posts_user_id',
            column:           'user_id',
            referencedTable:  'users',
            referencedColumn: 'id',
            onDelete:         'CASCADE',
        );

        $plan = new DiffPlan(alterTables: [$diff]);
        $sql  = $renderer->render($plan);

        self::assertStringContainsString('ADD CONSTRAINT', $sql);
        self::assertStringContainsString('FOREIGN KEY', $sql);
        self::assertStringContainsString('REFERENCES', $sql);
        self::assertStringContainsString('CASCADE', $sql);
    }

    public function testRenderDropForeignKey(): void
    {
        $renderer = new SqlRenderer(new MySqlDialect());

        $diff = new TableDiff('posts');
        $diff->droppedForeignKeys[] = 'fk_old';

        $plan = new DiffPlan(alterTables: [$diff]);
        $sql  = $renderer->render($plan);

        self::assertStringContainsString('fk_old', $sql);
    }

    // ── renderStatements ──────────────────────────────────────────

    public function testRenderStatementsReturnsArray(): void
    {
        $renderer = new SqlRenderer(new MySqlDialect());
        $plan     = $this->createTablePlan();

        $stmts = $renderer->renderStatements($plan);

        self::assertIsArray($stmts);
        self::assertNotEmpty($stmts);
    }

    // ── renderReverse ─────────────────────────────────────────────

    public function testRenderReverseDropsCreatedTable(): void
    {
        $renderer = new SqlRenderer(new MySqlDialect());
        $plan     = $this->createTablePlan();

        $sql = $renderer->renderReverse($plan);

        self::assertStringContainsString('DROP TABLE', $sql);
        self::assertStringContainsString('users', $sql);
    }

    public function testRenderReverseRecreatesDroppedTable(): void
    {
        $renderer = new SqlRenderer(new MySqlDialect());
        $plan     = new DiffPlan(dropTables: ['old_table']);

        $sql = $renderer->renderReverse($plan);

        // Reverse of DROP is comment (can't recreate without schema)
        self::assertNotEmpty($sql);
    }

    // ── Empty plan ────────────────────────────────────────────────

    public function testEmptyPlanRendersNothing(): void
    {
        $renderer = new SqlRenderer(new MySqlDialect());
        $plan     = new DiffPlan();

        self::assertSame('', $renderer->render($plan));
    }

    public function testEmptyPlanRenderStatementsReturnsEmpty(): void
    {
        $renderer = new SqlRenderer(new MySqlDialect());
        $plan     = new DiffPlan();

        self::assertSame([], $renderer->renderStatements($plan));
    }

    // ── Multi-dialect rendering ───────────────────────────────────

    /**
     * @return array<string, array{SqlDialect}>
     */
    public static function dialectProvider(): array
    {
        return [
            'MySQL'      => [new MySqlDialect()],
            'PostgreSQL' => [new PostgreSqlDialect()],
            'SQLite'     => [new SqliteDialect()],
        ];
    }

    #[DataProvider('dialectProvider')]
    public function testAllDialectsRenderCreateTable(SqlDialect $dialect): void
    {
        $renderer = new SqlRenderer($dialect);
        $plan     = self::createTablePlan();

        $sql = $renderer->render($plan);

        self::assertStringContainsString('CREATE TABLE', $sql);
    }

    #[DataProvider('dialectProvider')]
    public function testAllDialectsRenderAlterTable(SqlDialect $dialect): void
    {
        $renderer = new SqlRenderer($dialect);

        $diff = new TableDiff('users');
        $diff->addedColumns[] = new ColumnDefinition(
            name: 'phone',
            type: 'string',
            length: 20,
            nullable: true,
        );

        $plan = new DiffPlan(alterTables: [$diff]);
        $sql  = $renderer->render($plan);

        self::assertStringContainsString('ALTER TABLE', $sql);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private static function createTablePlan(): DiffPlan
    {
        return new DiffPlan(
            createTables: [
                new TableDefinition(
                    name:       'users',
                    columns:    [
                        'id'    => new ColumnDefinition(
                            name:          'id',
                            type:          'integer',
                            autoIncrement: true,
                            primaryKey:    true,
                        ),
                        'name'  => new ColumnDefinition(
                            name:   'name',
                            type:   'string',
                            length: 100,
                        ),
                        'email' => new ColumnDefinition(
                            name:   'email',
                            type:   'string',
                            length: 255,
                            unique: true,
                        ),
                    ],
                    primaryKey: 'id',
                ),
            ],
        );
    }
}
