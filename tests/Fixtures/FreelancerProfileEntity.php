<?php

declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Fixtures;

use MonkeysLegion\Entity\Attributes\Field;
use MonkeysLegion\Entity\Attributes\Id;
use MonkeysLegion\Entity\Attributes\OneToOne;

/**
 * Entity with shared PK/FK pattern — PK is NOT named "id".
 * $user_id is both the primary key and implicitly a foreign key to User.
 */
class FreelancerProfileEntity
{
    #[Id]
    #[Field(type: 'uuid', primaryKey: true)]
    public string $user_id;

    #[OneToOne(targetEntity: UserEntity::class, inversedBy: 'freelancerProfile')]
    public ?UserEntity $user = null;

    #[Field(type: 'string', length: 200, nullable: true)]
    public ?string $headline = null;

    #[Field(type: 'text', nullable: true)]
    public ?string $bio = null;

    #[Field(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    public ?string $hourly_rate = null;
}
