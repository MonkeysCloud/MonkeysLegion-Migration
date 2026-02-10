<?php

declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Fixtures;

use MonkeysLegion\Entity\Attributes\Field;
use MonkeysLegion\Entity\Attributes\Id;
use MonkeysLegion\Entity\Attributes\ManyToOne;

/**
 * Entity with ManyToOne FK to PostEntity and UserEntity.
 * Tests FK type resolution and _id suffix handling.
 */
class CommentEntity
{
    #[Id]
    #[Field(type: 'integer', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'text')]
    public string $body;

    #[ManyToOne(targetEntity: PostEntity::class, inversedBy: 'comments')]
    public ?PostEntity $post = null;

    #[ManyToOne(targetEntity: UserEntity::class, inversedBy: 'comments')]
    public ?UserEntity $author = null;
}
