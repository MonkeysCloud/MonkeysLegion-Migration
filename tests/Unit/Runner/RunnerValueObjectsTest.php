<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Unit\Runner;

use DateTimeImmutable;
use MonkeysLegion\Migration\Runner\MigrationStatus;
use MonkeysLegion\Migration\Runner\RunResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrationStatus::class)]
#[CoversClass(RunResult::class)]
final class RunnerValueObjectsTest extends TestCase
{
    // ── MigrationStatus ───────────────────────────────────────────

    public function testMigrationStatusPendingState(): void
    {
        $status = new MigrationStatus(
            name: 'M20260101_CreateUsers',
            ran:  false,
        );

        self::assertSame('M20260101_CreateUsers', $status->name);
        self::assertFalse($status->ran);
        self::assertNull($status->batch);
        self::assertNull($status->executedAt);
    }

    public function testMigrationStatusRanState(): void
    {
        $now = new DateTimeImmutable();

        $status = new MigrationStatus(
            name:       'M20260101_CreateUsers',
            ran:        true,
            batch:      3,
            executedAt: $now,
        );

        self::assertTrue($status->ran);
        self::assertSame(3, $status->batch);
        self::assertSame($now, $status->executedAt);
    }

    public function testMigrationStatusLabel(): void
    {
        $pending = new MigrationStatus(name: 'M20260101_Users', ran: false);
        $ran     = new MigrationStatus(name: 'M20260101_Users', ran: true, batch: 1);

        self::assertStringContainsString('Pending', $pending->label);
        self::assertStringContainsString('Ran', $ran->label);
    }

    // ── RunResult ─────────────────────────────────────────────────

    public function testRunResultSuccessful(): void
    {
        $result = new RunResult(
            executed:   ['M001', 'M002'],
            failed:     [],
            durationMs: 42.5,
        );

        self::assertSame(['M001', 'M002'], $result->executed);
        self::assertSame([], $result->failed);
        self::assertSame(42.5, $result->durationMs);
        self::assertNull($result->error);
        self::assertTrue($result->success);
        self::assertSame(2, $result->total);
    }

    public function testRunResultWithFailures(): void
    {
        $result = new RunResult(
            executed:   ['M001'],
            failed:     ['M002'],
            durationMs: 100.0,
            error:      'SQL error in M002',
        );

        self::assertFalse($result->success);
        self::assertSame(2, $result->total);
        self::assertSame('SQL error in M002', $result->error);
    }

    public function testRunResultEmpty(): void
    {
        $result = new RunResult();

        self::assertSame([], $result->executed);
        self::assertSame([], $result->failed);
        self::assertSame(0.0, $result->durationMs);
        self::assertTrue($result->success);
        self::assertSame(0, $result->total);
    }

    public function testRunResultWithOnlyError(): void
    {
        $result = new RunResult(
            executed:   [],
            failed:     [],
            durationMs: 0.0,
            error:      'No migrations found',
        );

        // error is set → success is false (error !== null)
        self::assertFalse($result->success);
    }

    // ── Value object immutability ─────────────────────────────────

    public function testMigrationStatusIsReadonly(): void
    {
        $status = new MigrationStatus(name: 'test', ran: true);

        $ref  = new \ReflectionClass($status);
        $prop = $ref->getProperty('name');

        self::assertTrue($prop->isReadOnly());
    }

    public function testRunResultIsReadonly(): void
    {
        $result = new RunResult();

        $ref  = new \ReflectionClass($result);
        $prop = $ref->getProperty('executed');

        self::assertTrue($prop->isReadOnly());
    }
}
