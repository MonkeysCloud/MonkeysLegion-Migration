<?php

declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Unit\Security;

use InvalidArgumentException;
use MonkeysLegion\Migration\Security\IdentifierValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 */
#[CoversClass(\MonkeysLegion\Migration\Security\IdentifierValidator::class)]
final class IdentifierValidatorTest extends TestCase
{
    // ── Valid identifiers ───────────────────────────────────────────

    public function testValidSimpleIdentifier(): void
    {
        $this->expectNotToPerformAssertions();
        IdentifierValidator::validate('users');
    }

    public function testValidUnderscoreIdentifier(): void
    {
        $this->expectNotToPerformAssertions();
        IdentifierValidator::validate('user_profiles');
    }

    public function testValidLeadingUnderscore(): void
    {
        $this->expectNotToPerformAssertions();
        IdentifierValidator::validate('_internal');
    }

    public function testValidWithNumbers(): void
    {
        $this->expectNotToPerformAssertions();
        IdentifierValidator::validate('table2');
    }

    // ── Invalid identifiers ────────────────────────────────────────

    public function testEmptyStringThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be empty');
        IdentifierValidator::validate('');
    }

    public function testTooLongThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum length');
        IdentifierValidator::validate(str_repeat('a', 65));
    }

    public function testSqlCommentInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dangerous pattern');
        IdentifierValidator::validate('users--drop');
    }

    public function testBlockCommentInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dangerous pattern');
        IdentifierValidator::validate('users/*evil*/');
    }

    public function testSemicolonInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dangerous pattern');
        IdentifierValidator::validate('users;DROP');
    }

    public function testSingleQuoteInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dangerous pattern');
        IdentifierValidator::validate("users'evil");
    }

    public function testStartsWithNumber(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid characters');
        IdentifierValidator::validate('123abc');
    }

    public function testSpacesInIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IdentifierValidator::validate('user name');
    }

    public function testSpecialCharsInIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IdentifierValidator::validate('user@name');
    }

    // ── Reserved words ─────────────────────────────────────────────

    public function testRecognizesReservedWord(): void
    {
        $this->assertTrue(IdentifierValidator::isReservedWord('SELECT'));
        $this->assertTrue(IdentifierValidator::isReservedWord('select'));
        $this->assertTrue(IdentifierValidator::isReservedWord('TABLE'));
    }

    public function testNonReservedWord(): void
    {
        $this->assertFalse(IdentifierValidator::isReservedWord('users'));
        $this->assertFalse(IdentifierValidator::isReservedWord('posts'));
    }

    // ── Warning mode ───────────────────────────────────────────────

    public function testWarnsForReservedWord(): void
    {
        $warnings = IdentifierValidator::validateWithWarnings('status');
        // 'status' is not in our reserved list, so no warnings
        $this->assertEmpty($warnings);
    }

    public function testWarnsForSelectAsIdentifier(): void
    {
        // This should still validate (reserved words are valid when quoted)
        // but produce a warning
        $warnings = IdentifierValidator::validateWithWarnings('ORDER');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('reserved word', $warnings[0]);
    }
}
