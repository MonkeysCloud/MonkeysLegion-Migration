<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Security;

use InvalidArgumentException;

/**
 * MonkeysLegion Framework — Migration Package
 *
 * Validates SQL identifiers (table names, column names, index names)
 * against injection patterns and reserved words.
 *
 * All identifier quoting in the dialect layer MUST pass through this
 * validator first. This is a defense-in-depth measure — even though
 * identifiers come from entity attributes (not user input), a
 * compromised or malformed attribute could inject SQL.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class IdentifierValidator
{
    /**
     * Maximum identifier length (MySQL limit).
     */
    private const int MAX_LENGTH = 64;

    /**
     * Allowed identifier pattern: letters, digits, underscores.
     * Must start with a letter or underscore.
     */
    private const string PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * Dangerous patterns that should never appear in identifiers.
     *
     * @var list<string>
     */
    private const array DANGEROUS_PATTERNS = [
        '--',
        '/*',
        '*/',
        ';',
        '\'',
        '"',
        '\\',
        "\x00",
    ];

    /**
     * SQL reserved words that should trigger a warning.
     * Not exhaustive — covers the most commonly hit ones.
     *
     * @var list<string>
     */
    private const array RESERVED_WORDS = [
        'ADD', 'ALL', 'ALTER', 'AND', 'AS', 'ASC',
        'BETWEEN', 'BY',
        'CASE', 'CHECK', 'COLUMN', 'CONSTRAINT', 'CREATE', 'CROSS', 'CURRENT',
        'DATABASE', 'DEFAULT', 'DELETE', 'DESC', 'DISTINCT', 'DROP',
        'ELSE', 'END', 'EXCEPT', 'EXISTS',
        'FALSE', 'FOREIGN', 'FROM', 'FULL',
        'GROUP',
        'HAVING',
        'IF', 'IN', 'INDEX', 'INNER', 'INSERT', 'INTERSECT', 'INTO', 'IS',
        'JOIN',
        'KEY',
        'LEFT', 'LIKE', 'LIMIT',
        'NOT', 'NULL',
        'ON', 'OR', 'ORDER', 'OUTER',
        'PRIMARY',
        'REFERENCES', 'RIGHT', 'ROLLBACK',
        'SELECT', 'SET',
        'TABLE', 'THEN', 'TO', 'TRUE', 'TRUNCATE',
        'UNION', 'UNIQUE', 'UPDATE', 'USING',
        'VALUES', 'VIEW',
        'WHERE', 'WITH',
    ];

    /**
     * Validate an identifier for safe use in SQL.
     *
     * @throws InvalidArgumentException If the identifier is invalid.
     */
    public static function validate(string $identifier): void
    {
        if ($identifier === '') {
            throw new InvalidArgumentException('SQL identifier cannot be empty.');
        }

        if (strlen($identifier) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'SQL identifier "%s" exceeds maximum length of %d characters.',
                    $identifier,
                    self::MAX_LENGTH,
                ),
            );
        }

        // Check for dangerous patterns
        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (str_contains($identifier, $pattern)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'SQL identifier "%s" contains dangerous pattern "%s".',
                        $identifier,
                        $pattern,
                    ),
                );
            }
        }

        // Validate character pattern
        if (!preg_match(self::PATTERN, $identifier)) {
            throw new InvalidArgumentException(
                sprintf(
                    'SQL identifier "%s" contains invalid characters. '
                    . 'Must match pattern: %s',
                    $identifier,
                    self::PATTERN,
                ),
            );
        }
    }

    /**
     * Check if a word is a SQL reserved keyword.
     */
    public static function isReservedWord(string $word): bool
    {
        return in_array(strtoupper($word), self::RESERVED_WORDS, true);
    }

    /**
     * Validate and warn if identifier is a reserved word.
     * Does not throw — reserved words are valid when quoted,
     * but may cause confusion.
     *
     * @return list<string> Warning messages (empty if none).
     */
    public static function validateWithWarnings(string $identifier): array
    {
        self::validate($identifier);

        $warnings = [];

        if (self::isReservedWord($identifier)) {
            $warnings[] = sprintf(
                'Identifier "%s" is a SQL reserved word. '
                . 'It will be quoted but may cause confusion.',
                $identifier,
            );
        }

        return $warnings;
    }
}
