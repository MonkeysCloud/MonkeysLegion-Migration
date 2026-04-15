<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration\Tests\Fixtures;

use MonkeysLegion\Entity\Attributes\AuditTrail;
use MonkeysLegion\Entity\Attributes\Entity;
use MonkeysLegion\Entity\Attributes\Field;
use MonkeysLegion\Entity\Attributes\Id;
use MonkeysLegion\Entity\Attributes\Uuid;
use MonkeysLegion\Entity\Attributes\Versioned;
use MonkeysLegion\Entity\Attributes\Virtual;

/**
 * Entity with #[AuditTrail], #[Versioned], #[Virtual], and #[Uuid] PK.
 */
#[Entity(table: 'audit_records')]
#[AuditTrail]
class AuditEntity
{
    #[Id]
    #[Uuid]
    #[Field(type: 'uuid', primaryKey: true)]
    public string $id;

    #[Field(type: 'string', length: 255)]
    public string $action;

    #[Versioned]
    #[Field(type: 'integer', default: 1)]
    public int $version = 1;

    #[Virtual]
    public string $computedLabel = '';
}
