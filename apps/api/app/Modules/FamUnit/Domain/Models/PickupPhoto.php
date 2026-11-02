<?php

namespace App\Modules\FamUnit\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;

/**
 * REQ-FAM-UNIT/datos.md §8.1, §8.7 (1.14b-a, `REQ-FAM-UNIT-005`, `RN-FAM-UNIT-83`). Fotografía de recogida de una persona
 * (tutor o tercero): una viva por persona, privada y reprocesada sin metadatos. Para verificar visualmente a quien recoge
 * (limitación de finalidad): no es la foto de perfil de nadie.
 *
 * ADR-035: `Selective`. Se registran `mime_type`, `byte_size` y `deleted_at`; `person_id` y `object_key` se redactan como
 * `identifier` por no estar en la lista (`datos.md §8.7`): la clave del objeto no sale nunca del servidor. Escrituras por
 * instancia, nunca masivas (AR-15): nada es asignable en masa. **Único servicio sancionado por `AR-10` para consultarlo**:
 * `PickupListService` (recurso `autorizado_recogida`); los demás módulos no usan este modelo (AR-01).
 *
 * @mixin IdeHelperPickupPhoto
 */
class PickupPhoto extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    protected $table = 'pickup_photos';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = ['mime_type', 'byte_size', 'deleted_at'];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    /** Nada es asignable en masa: el servicio rellena la fotografía por instancia (AR-15). */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['byte_size' => 'integer'];
    }
}
