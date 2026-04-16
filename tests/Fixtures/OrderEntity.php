<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Fixtures;

use MonkeysLegion\Entity\Attributes\Column;
use MonkeysLegion\Entity\Attributes\Entity;
use MonkeysLegion\Entity\Attributes\Field;
use MonkeysLegion\Entity\Attributes\Id;
use MonkeysLegion\Entity\Attributes\Index;
use MonkeysLegion\Entity\Attributes\ManyToOne;
use MonkeysLegion\Entity\Attributes\SoftDeletes;
use MonkeysLegion\Entity\Attributes\Timestamps;

/**
 * Entity with #[Timestamps], #[SoftDeletes], composite #[Index],
 * #[Column] rename, and decimal fields.
 */
#[Entity(table: 'orders')]
#[Timestamps]
#[SoftDeletes]
#[Index(columns: ['user_id', 'status'], name: 'idx_orders_user_status')]
class OrderEntity
{
    #[Id]
    #[Field(type: 'unsignedBigInt', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', length: 36)]
    #[Column(name: 'order_number')]
    #[Index(unique: true)]
    public string $orderNumber;

    #[Field(type: 'decimal', precision: 10, scale: 2, default: '0.00')]
    public string $total;

    #[Field(type: 'enum', enumValues: ['pending', 'paid', 'shipped', 'cancelled'], default: 'pending')]
    public string $status;

    #[Field(type: 'json', nullable: true)]
    public ?array $metadata = null;

    #[ManyToOne(targetEntity: UserEntity::class)]
    public ?UserEntity $user = null;
}
