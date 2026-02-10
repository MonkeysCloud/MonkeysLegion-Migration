<?php

declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Fixtures;

use MonkeysLegion\Entity\Attributes\Field;
use MonkeysLegion\Entity\Attributes\Id;

/**
 * Entity with auto-increment integer PK.
 */
class PostEntity
{
    #[Id]
    #[Field(type: 'integer', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', length: 255)]
    public string $title;

    #[Field(type: 'text')]
    public string $body;

    #[Field(type: 'enum', enumValues: ['draft', 'published', 'archived'], default: 'draft')]
    public string $status;

    #[Field(type: 'datetime')]
    public \DateTimeImmutable $created_at;
}
