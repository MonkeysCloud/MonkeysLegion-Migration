<?php

declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Unit\Diff;

use MonkeysLegion\Migration\Diff\ColumnChange;
use MonkeysLegion\Migration\Diff\DiffPlan;
use MonkeysLegion\Migration\Diff\SchemaDiffer;
use MonkeysLegion\Migration\Diff\TableDiff;
use MonkeysLegion\Migration\Schema\ColumnDefinition;
use MonkeysLegion\Migration\Schema\ForeignKeyDefinition;
use MonkeysLegion\Migration\Schema\IndexDefinition;
use MonkeysLegion\Migration\Schema\TableDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 */
#[CoversClass(\MonkeysLegion\Migration\Diff\SchemaDiffer::class)]
#[CoversClass(\MonkeysLegion\Migration\Diff\DiffPlan::class)]
#[CoversClass(\MonkeysLegion\Migration\Diff\TableDiff::class)]
#[CoversClass(\MonkeysLegion\Migration\Diff\ColumnChange::class)]
final class SchemaDifferTest extends TestCase
{
    private SchemaDiffer $differ;

    protected function setUp(): void
    {
        $this->differ = new SchemaDiffer();
    }

    // ── Table creation ─────────────────────────────────────────────

    public function testNewTableAppearsInCreateTables(): void
    {
        $desired = [
            'users' => new TableDefinition(
                name: 'users',
                columns: [
                    'id' => new ColumnDefinition(name: 'id', type: 'int', autoIncrement: true),
                ],
            ),
        ];

        $plan = $this->differ->diff($desired, []);

        $this->assertCount(1, $plan->createTables);
        $this->assertSame('users', $plan->createTables[0]->name);
        $this->assertEmpty($plan->dropTables);
    }

    // ── Table dropping ─────────────────────────────────────────────

    public function testOrphanTableAppearsInDropTables(): void
    {
        $current = [
            'obsolete' => new TableDefinition(
                name: 'obsolete',
                columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
            ),
        ];

        $plan = $this->differ->diff([], $current);

        $this->assertContains('obsolete', $plan->dropTables);
    }

    public function testProtectedTableIsNeverDropped(): void
    {
        $current = [
            'migrations' => new TableDefinition(
                name: 'migrations',
                columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
            ),
            'ml_migrations' => new TableDefinition(
                name: 'ml_migrations',
                columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
            ),
        ];

        $plan = $this->differ->diff([], $current);

        $this->assertNotContains('migrations', $plan->dropTables);
        $this->assertNotContains('ml_migrations', $plan->dropTables);
    }

    public function testCustomProtectedTable(): void
    {
        $this->differ->addProtectedTable('sessions');

        $current = [
            'sessions' => new TableDefinition(
                name: 'sessions',
                columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
            ),
        ];

        $plan = $this->differ->diff([], $current);

        $this->assertNotContains('sessions', $plan->dropTables);
    }

    // ── Column adding ──────────────────────────────────────────────

    public function testNewColumnDetected(): void
    {
        $desired = [
            'users' => new TableDefinition(
                name: 'users',
                columns: [
                    'id'    => new ColumnDefinition(name: 'id', type: 'int'),
                    'email' => new ColumnDefinition(name: 'email', type: 'string'),
                ],
            ),
        ];

        $current = [
            'users' => new TableDefinition(
                name: 'users',
                columns: [
                    'id' => new ColumnDefinition(name: 'id', type: 'int'),
                ],
            ),
        ];

        $plan = $this->differ->diff($desired, $current);

        $this->assertCount(1, $plan->alterTables);
        $this->assertCount(1, $plan->alterTables[0]->addedColumns);
        $this->assertSame('email', $plan->alterTables[0]->addedColumns[0]->name);
    }

    // ── Column dropping ────────────────────────────────────────────

    public function testRemovedColumnDetected(): void
    {
        $desired = [
            'users' => new TableDefinition(
                name: 'users',
                columns: [
                    'id' => new ColumnDefinition(name: 'id', type: 'int'),
                ],
                primaryKey: 'id',
            ),
        ];

        $current = [
            'users' => new TableDefinition(
                name: 'users',
                columns: [
                    'id'     => new ColumnDefinition(name: 'id', type: 'int'),
                    'legacy' => new ColumnDefinition(name: 'legacy', type: 'string'),
                ],
                primaryKey: 'id',
            ),
        ];

        $plan = $this->differ->diff($desired, $current);

        $this->assertCount(1, $plan->alterTables);
        $this->assertContains('legacy', $plan->alterTables[0]->droppedColumns);
    }

    public function testPrimaryKeyColumnNeverDropped(): void
    {
        $desired = [
            'users' => new TableDefinition(
                name: 'users',
                columns: [
                    'id' => new ColumnDefinition(name: 'id', type: 'int'),
                ],
                primaryKey: 'id',
            ),
        ];

        $current = [
            'users' => new TableDefinition(
                name: 'users',
                columns: [
                    'id' => new ColumnDefinition(name: 'id', type: 'int'),
                ],
                primaryKey: 'id',
            ),
        ];

        $plan = $this->differ->diff($desired, $current);

        // No changes — empty
        $this->assertTrue($plan->isEmpty());
    }

    // ── Column modification ────────────────────────────────────────

    public function testColumnTypeChangeDetected(): void
    {
        $desired = [
            'users' => new TableDefinition(
                name: 'users',
                columns: [
                    'id'  => new ColumnDefinition(name: 'id', type: 'int'),
                    'age' => new ColumnDefinition(name: 'age', type: 'bigint'),
                ],
            ),
        ];

        $current = [
            'users' => new TableDefinition(
                name: 'users',
                columns: [
                    'id'  => new ColumnDefinition(name: 'id', type: 'int'),
                    'age' => new ColumnDefinition(name: 'age', type: 'int'),
                ],
            ),
        ];

        $plan = $this->differ->diff($desired, $current);

        $this->assertCount(1, $plan->alterTables);
        $this->assertCount(1, $plan->alterTables[0]->modifiedColumns);
        $this->assertSame('age', $plan->alterTables[0]->modifiedColumns[0]->columnName);
    }

    public function testColumnNullabilityChangeDetected(): void
    {
        $desired = [
            'users' => new TableDefinition(
                name: 'users',
                columns: [
                    'id'   => new ColumnDefinition(name: 'id', type: 'int'),
                    'name' => new ColumnDefinition(name: 'name', type: 'string', nullable: true),
                ],
            ),
        ];

        $current = [
            'users' => new TableDefinition(
                name: 'users',
                columns: [
                    'id'   => new ColumnDefinition(name: 'id', type: 'int'),
                    'name' => new ColumnDefinition(name: 'name', type: 'string', nullable: false),
                ],
            ),
        ];

        $plan = $this->differ->diff($desired, $current);

        $this->assertCount(1, $plan->alterTables[0]->modifiedColumns);
    }

    // ── Index diff ─────────────────────────────────────────────────

    public function testNewIndexDetected(): void
    {
        $desired = [
            'users' => new TableDefinition(
                name: 'users',
                columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
                indexes: [new IndexDefinition(name: 'idx_email', columns: ['email'])],
            ),
        ];

        $current = [
            'users' => new TableDefinition(
                name: 'users',
                columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
            ),
        ];

        $plan = $this->differ->diff($desired, $current);

        $this->assertCount(1, $plan->alterTables[0]->addedIndexes);
    }

    public function testDroppedIndexDetected(): void
    {
        $desired = [
            'users' => new TableDefinition(
                name: 'users',
                columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
            ),
        ];

        $current = [
            'users' => new TableDefinition(
                name: 'users',
                columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
                indexes: [new IndexDefinition(name: 'idx_old', columns: ['old'])],
            ),
        ];

        $plan = $this->differ->diff($desired, $current);

        $this->assertContains('idx_old', $plan->alterTables[0]->droppedIndexes);
    }

    // ── FK diff ────────────────────────────────────────────────────

    public function testNewFkDetected(): void
    {
        $desired = [
            'posts' => new TableDefinition(
                name: 'posts',
                columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
                foreignKeys: [
                    new ForeignKeyDefinition(
                        name: 'fk_user', column: 'user_id',
                        referencedTable: 'users', referencedColumn: 'id',
                    ),
                ],
            ),
        ];

        $current = [
            'posts' => new TableDefinition(
                name: 'posts',
                columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
            ),
        ];

        $plan = $this->differ->diff($desired, $current);

        $this->assertCount(1, $plan->alterTables[0]->addedForeignKeys);
    }

    public function testChangedFkDefinitionReplaced(): void
    {
        $desired = [
            'posts' => new TableDefinition(
                name: 'posts',
                columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
                foreignKeys: [
                    new ForeignKeyDefinition(
                        name: 'fk_posts_author',
                        column: 'author_id',
                        referencedTable: 'users',
                        referencedColumn: 'id',
                        onDelete: 'CASCADE',
                    ),
                ],
            ),
        ];

        $current = [
            'posts' => new TableDefinition(
                name: 'posts',
                columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
                foreignKeys: [
                    new ForeignKeyDefinition(
                        name: 'fk_posts_author_old',
                        column: 'author_id',
                        referencedTable: 'members',
                        referencedColumn: 'id',
                        onDelete: 'RESTRICT',
                    ),
                ],
            ),
        ];

        $plan = $this->differ->diff($desired, $current);

        $this->assertCount(1, $plan->alterTables);
        $this->assertCount(1, $plan->alterTables[0]->addedForeignKeys);
        $this->assertContains('fk_posts_author_old', $plan->alterTables[0]->droppedForeignKeys);
    }

    // ── DiffPlan helpers ───────────────────────────────────────────

    public function testDiffPlanIsEmptyWhenNoChanges(): void
    {
        $plan = new DiffPlan();
        $this->assertTrue($plan->isEmpty());
    }

    public function testDiffPlanIsNotEmptyWithCreateTables(): void
    {
        $plan = new DiffPlan(createTables: [
            new TableDefinition(name: 'x', columns: []),
        ]);
        $this->assertFalse($plan->isEmpty());
    }

    public function testDiffPlanChangeCount(): void
    {
        $plan = new DiffPlan(
            createTables: [new TableDefinition(name: 'x', columns: [])],
            dropTables: ['y'],
            alterTables: [
                new TableDiff(
                    tableName: 'z',
                    addedColumns: [new ColumnDefinition(name: 'a', type: 'int')],
                ),
            ],
        );

        // 1 create + 1 drop + 1 added column = 3
        $this->assertSame(3, $plan->changeCount());
    }

    public function testDiffPlanToHumanReadable(): void
    {
        $plan = new DiffPlan(
            createTables: [
                new TableDefinition(
                    name: 'users',
                    columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
                ),
            ],
        );

        $text = $plan->toHumanReadable();
        $this->assertStringContainsString('CREATE TABLE users', $text);
    }

    // ── ColumnChange helper ────────────────────────────────────────

    public function testColumnChangeDescribe(): void
    {
        $change = new ColumnChange(
            columnName: 'age',
            from: new ColumnDefinition(name: 'age', type: 'int', nullable: false),
            to: new ColumnDefinition(name: 'age', type: 'bigint', nullable: true),
        );

        $desc = $change->describe();
        $this->assertStringContainsString('type: int → bigint', $desc);
        $this->assertStringContainsString('nullable → true', $desc);
    }

    // ── FK dependency ordering ─────────────────────────────────────

    public function testFkDependencyOrdering(): void
    {
        $users = new TableDefinition(
            name: 'users',
            columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
        );

        $posts = new TableDefinition(
            name: 'posts',
            columns: ['id' => new ColumnDefinition(name: 'id', type: 'int')],
            foreignKeys: [
                new ForeignKeyDefinition(
                    name: 'fk_user', column: 'user_id',
                    referencedTable: 'users', referencedColumn: 'id',
                ),
            ],
        );

        // Pass posts first, users second — differ should reorder
        $plan = $this->differ->diff(
            ['posts' => $posts, 'users' => $users],
            [],
        );

        // users should come before posts in createTables
        $names = array_map(fn($t) => $t->name, $plan->createTables);
        $usersIdx = array_search('users', $names);
        $postsIdx = array_search('posts', $names);

        $this->assertLessThan($postsIdx, $usersIdx, 'Referenced table should be created first');
    }

    // ── TableDiff helpers ──────────────────────────────────────────

    public function testTableDiffIsEmpty(): void
    {
        $diff = new TableDiff(tableName: 'users');
        $this->assertTrue($diff->isEmpty());
    }

    public function testTableDiffDescribe(): void
    {
        $diff = new TableDiff(
            tableName: 'users',
            addedColumns: [new ColumnDefinition(name: 'phone', type: 'string')],
            droppedColumns: ['legacy'],
        );

        $desc = $diff->describe();
        $this->assertStringContainsString('add columns: phone', $desc);
        $this->assertStringContainsString('drop columns: legacy', $desc);
    }
}
