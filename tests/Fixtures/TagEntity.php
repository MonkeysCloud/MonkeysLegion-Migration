<?php

declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Fixtures;

use MonkeysLegion\Entity\Attributes\Field;
use MonkeysLegion\Entity\Attributes\Id;
use MonkeysLegion\Entity\Attributes\JoinTable;
use MonkeysLegion\Entity\Attributes\ManyToMany;

/**
 * Entity with ManyToMany relationship & JoinTable.
 */
class TagEntity
{
    #[Id]
    #[Field(type: 'integer', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', length: 50)]
    public string $name;

    #[ManyToMany(
        targetEntity: PostEntity::class,
        joinTable: new JoinTable(
            name: 'post_tags',
            joinColumn: 'tag_id',
            inverseColumn: 'post_id',
        ),
    )]
    public array $posts = [];
}
