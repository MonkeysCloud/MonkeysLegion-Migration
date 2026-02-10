<?php

declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Fixtures;

use MonkeysLegion\Entity\Attributes\Field;
use MonkeysLegion\Entity\Attributes\Id;
use MonkeysLegion\Entity\Attributes\OneToMany;

/**
 * Standard entity with UUID PK named "id".
 */
class UserEntity
{
    #[Id]
    #[Field(type: 'uuid', primaryKey: true)]
    public string $id;

    #[Field(type: 'string', length: 100)]
    public string $name;

    #[Field(type: 'string', length: 255)]
    public string $email;

    #[Field(type: 'boolean', default: false)]
    public bool $is_active;

    #[Field(type: 'boolean', default: true)]
    public bool $enabled;

    #[OneToMany(targetEntity: CommentEntity::class, mappedBy: 'author')]
    public array $comments = [];
}
